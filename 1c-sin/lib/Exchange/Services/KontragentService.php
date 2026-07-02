<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use CCrmOwnerType;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\Enum\SegmentEnum;
use Rusgeocom\Rusgeocom\Exchange\Services\Builders\KontragentBuilder;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

class KontragentService
{
    use ExchangeHelperTrait;

    private const PRESET_BY_NAME = [
        'Организация' => 1,
        'Индивидуальный предприниматель' => 2,
        'Физическое лицо' => 3,
        'Юр. лицо (нерезидент)' => 4,
    ];

    private const ADDRESS_TYPE_BY_NAME = [
        'Юридический адрес' => EntityAddressType::Registered, // 6
        'Фактический адрес' => EntityAddressType::Primary,    // 1
    ];

    private static bool $isSyncFromOneC = false;

    public static function setSyncFromOneC(bool $value): void
    {
        self::$isSyncFromOneC = $value;
    }

    public static function isSyncFromOneC(): bool
    {
        return self::$isSyncFromOneC;
    }


    public static function sync(array $data): array
    {
        Loader::includeModule('crm');
        self::setSyncFromOneC(true);

        try {
            $guid = $data['guid'] ?? '';

            $companyId = null;
            $companyAction = null;

            if (!empty($data['company'])) {
                $companyResult = self::syncCompany($data['company']);
                if ($companyResult['status'] !== 'success') {
                    return $companyResult;
                }
                $companyId = $companyResult['id'];
                $companyAction = $companyResult['action'];
            }

            $requisiteInfo = self::findRequisiteByPriority($data);
            $requisiteId = $requisiteInfo['id'];
            $isNew = $requisiteId === null;

            if (!$isNew && $companyId) {
                self::updateRequisiteCompanyLink($requisiteId, $companyId);
            }

            $requisiteObj = new EntityRequisite();
            $fields = self::prepareRequisiteFields($data, $companyId);

            $result = $isNew
                ? $requisiteObj->add($fields)
                : $requisiteObj->update($requisiteId, $fields);

            if (!$result->isSuccess()) {
                return self::errorResult(
                    'REQUISITE_ERROR',
                    'Ошибка сохранения реквизита: ' . implode(', ', $result->getErrorMessages()),
                    $guid
                );
            }

            if ($isNew) {
                $requisiteId = $result->getId();
            }

            if (!empty($data['address'])) {
                self::syncAddresses((int)$requisiteId, $data['address']);
            }

            return [
                'status' => $isNew ? 'created' : 'updated',
                'b24_id' => $requisiteId,
                'guid' => $guid,
                'company' => [
                    'b24_id' => $companyId,
                    'guid' => $data['company']['guid'],
                    'status' => $companyAction,
                ],
            ];
        } finally {
            self::setSyncFromOneC(false);
        }
    }


    private static function findRequisiteByPriority(array $data): array
    {
        $r = new EntityRequisite();

        if (!empty($data['guid'])) {
            if ($id = self::fetchRequisiteId($r, ['=XML_ID' => $data['guid']])) {
                return self::found($id, 'guid');
            }
        }

        if (!empty($data['b24_id'])) {
            if ($id = self::fetchRequisiteId($r, ['=ID' => (int)$data['b24_id']])) {
                return self::found($id, 'b24_id');
            }
        }

        if (!empty($data['INN'])) {
            $filter = ['=RQ_INN' => (string)$data['INN']];
            if (!empty($data['KPP'])) {
                $filter['=RQ_KPP'] = (string)$data['KPP'];
            }
            if ($id = self::fetchRequisiteId($r, $filter)) {
                return self::found($id, 'inn_kpp');
            }
        }

        if (!empty($data['companyName'])) {
            if ($id = self::fetchRequisiteId($r, ['=RQ_COMPANY_NAME' => $data['companyName']])) {
                return self::found($id, 'company_name');
            }
        }

        return ['id' => null, 'found_by' => null];
    }

    private static function fetchRequisiteId(EntityRequisite $r, array $filter): ?int
    {
        $row = $r->getList(['filter' => $filter, 'select' => ['ID'], 'limit' => 1])->fetch();
        return $row ? (int)$row['ID'] : null;
    }

    private static function found(int $id, string $by): array
    {
        return ['id' => $id, 'found_by' => $by];
    }


    private static function syncCompany(array $data): array
    {
        if (empty($data)) {
            return self::errorResult('COMPANY_ERROR', 'Нет данных компании', '');
        }

        [$companyId, $foundBy] = self::findCompanyId($data);

        $factory = Container::getInstance()->getFactory(CCrmOwnerType::Company);
        if (!$factory) {
            return self::errorResult('COMPANY_ERROR', 'Фабрика компании не найдена', $data['guid'] ?? '');
        }

        $isNew = $companyId === null;
        $item = $isNew ? $factory->createItem() : $factory->getItem($companyId);

        if (!$isNew && !$item) {
            return self::errorResult('COMPANY_ERROR', "Компания {$companyId} не найдена в базе", $data['guid'] ?? '');
        }

        foreach (self::prepareCompanyFields($data) as $field => $value) {
            if ($item->hasField($field)) {
                $item->set($field, $value);
            }
        }

        $operation = $isNew
            ? $factory->getAddOperation($item)
            : $factory->getUpdateOperation($item);

        $result = $operation->disableCheckAccess()->disableSaveToHistory()->launch();

        if (!$result->isSuccess()) {
            return self::errorResult('COMPANY_SAVE_ERROR', 'Ошибка сохранения компании: ' . implode(', ', $result->getErrorMessages()), $data['guid'] ?? '');
        }

        $savedId = $item->getId();
        self::updateCompanyMultifields($savedId, $data);
        self::syncCompanyContacts($savedId, $data['contacts'] ?? []);

        return [
            'status' => 'success',
            'id' => $savedId,
            'action' => $isNew ? 'created' : 'updated',
            'found_by' => $foundBy,
            'guid' => $data['guid'] ?? '',
        ];
    }


    private static function findCompanyId(array $data): array
    {
        if (!empty($data['b24_id'])) {
            $row = CompanyTable::getList(['filter' => ['=ID' => (int)$data['b24_id']], 'select' => ['ID']])->fetch();
            if ($row) {
                return [(int)$row['ID'], 'b24_id'];
            }
        }

        if (!empty($data['guid'])) {
            $row = CompanyTable::getList(['filter' => ['=ORIGIN_ID' => $data['guid']], 'select' => ['ID']])->fetch();
            if ($row) {
                return [(int)$row['ID'], 'guid'];
            }
        }

        if (!empty($data['INN'])) {
            $row = (new EntityRequisite())->getList([
                'filter' => ['=RQ_INN' => (string)$data['INN'], '=ENTITY_TYPE_ID' => CCrmOwnerType::Company],
                'select' => ['ENTITY_ID'],
                'limit' => 1,
            ])->fetch();
            if ($row && (int)$row['ENTITY_ID'] > 0) {
                return [(int)$row['ENTITY_ID'], 'inn_through_requisite'];
            }
        }

        if (!empty($data['companyName'])) {
            $row = CompanyTable::getList(['filter' => ['=%TITLE' => $data['companyName']], 'select' => ['ID'], 'limit' => 1])->fetch();
            if ($row) {
                return [(int)$row['ID'], 'company_name'];
            }
        }

        return [null, null];
    }

    private static function prepareCompanyFields(array $data): array
    {
        $fields = [
            'TITLE' => $data['companyName'],
            'ORIGIN_ID' => $data['guid'],
            'ASSIGNED_BY_ID' => !empty($data['manage_id']['b24_id']) ? $data['manage_id']['b24_id'] : SearchEntityService::searchUser($data['manage_id']['guid']) ?? 1,
            'UF_CRM_1774519329551' => SegmentEnum::getIdByText($data['segment']),
            'UF_CRM_1774519961417' => $data['statusWork'],
            'UF_CRM_COMPANY_3885072309690' => $data['markDelete'],
            'UF_CRM_1776097823371' => $data['isBuyer'],
            'UF_CRM_1776097831202' => $data['isSupplier'],
            'UF_CRM_1776097840683' => $data['isCompetitor'],
            'UF_CRM_1776097854105' => $data['isOther'],
        ];

        if (!empty($data['businessRegion_id'])) {
            $regionId = !empty($data['businessRegion_id']['b24_id'])
                ? $data['businessRegion_id']['b24_id']
                : SearchEntityService::searchSmartProcess($data['businessRegion_id']['guid'],EntityType::SMART_PROCESS_BUSINESS_REGION);
            if ($regionId) {
                $fields['PARENT_ID_1032'] = $regionId;
            }
        }

        return $fields;
    }

    private static function updateCompanyMultifields(int $companyId, array $data): void
    {
        $mf = new \CCrmFieldMulti();

        $dbRes = \CCrmFieldMulti::GetList([], ['ENTITY_ID' => 'COMPANY', 'ELEMENT_ID' => $companyId]);
        while ($row = $dbRes->Fetch()) {
            $mf->Delete($row['ID']);
        }

        foreach (array_filter(['PHONE' => $data['phone'] ?? null, 'EMAIL' => $data['email'] ?? null]) as $typeId => $value) {
            $mf->Add([
                'ENTITY_ID' => 'COMPANY',
                'ELEMENT_ID' => $companyId,
                'TYPE_ID' => $typeId,
                'VALUE_TYPE' => 'WORK',
                'VALUE' => trim((string)$value),
            ]);
        }
    }

    private static function syncCompanyContacts(int $companyId, array $contacts): void
    {
        $newIds = self::resolveContactIds($contacts);

        $existingIds = array_map(
            'intval',
            array_column(
                ContactCompanyTable::getList([
                    'filter' => ['=COMPANY_ID' => $companyId],
                    'select' => ['CONTACT_ID'],
                ])->fetchAll(),
                'CONTACT_ID'
            )
        );

        $toUnbind = array_diff($existingIds, $newIds);
        $toBind = array_diff($newIds, $existingIds);

        if (!empty($toUnbind)) {
            ContactCompanyTable::unbindContacts(
                $companyId,
                array_map(fn(int $id) => ['CONTACT_ID' => $id], array_values($toUnbind))
            );
        }

        if (!empty($toBind)) {
            ContactCompanyTable::bindContacts(
                $companyId,
                array_map(fn(int $id) => ['CONTACT_ID' => $id, 'IS_PRIMARY' => 'Y', 'SORT' => 10], array_values($toBind))
            );
        }
    }

    private static function resolveContactIds(array $contacts): array
    {
        $ids = [];
        foreach ($contacts as $contact) {
            if (!empty($contact['b24_id'])) {
                $ids[] = (int)$contact['b24_id'];
            } elseif (!empty($contact['guid'])) {
                $id = ContactService::getExistId($contact['guid']);
                if ($id) {
                    $ids[] = $id;
                }
            }
        }
        return array_unique($ids);
    }

    private static function updateRequisiteCompanyLink(int $requisiteId, int $companyId): void
    {
        $r = new EntityRequisite();
        $row = $r->getList(['filter' => ['=ID' => $requisiteId], 'select' => ['ENTITY_ID']])->fetch();

        if ($row && (int)$row['ENTITY_ID'] !== $companyId) {
            $r->update($requisiteId, [
                'ENTITY_TYPE_ID' => CCrmOwnerType::Company,
                'ENTITY_ID' => $companyId,
            ]);
        }
    }

    private static function prepareRequisiteFields(array $data, ?int $companyId): array
    {
        $fields = [
            'ENTITY_TYPE_ID' => CCrmOwnerType::Company,
            'ENTITY_ID' => $companyId ?? 0,
            'PRESET_ID' => self::PRESET_BY_NAME[$data['preset'] ?? 'Организация'] ?? 1,
            'NAME' => $data['companyName'] ?? '',
            'XML_ID' => $data['guid'] ?? '',
            'RQ_COMPANY_NAME' => $data['companyName'] ?? '',
            'RQ_COMPANY_FULL_NAME' => $data['companyFullName'] ?? $data['companyName'] ?? '',
            'UF_CRM_1774516144' => $data['markDelete'],
            'UF_CRM_1776109744' => $data['isWholesaler'],
        ];

        foreach (['INN' => 'RQ_INN', 'KPP' => 'RQ_KPP', 'OGRN' => 'RQ_OGRN'] as $src => $dst) {
            if (isset($data[$src]) && $data[$src] !== '') {
                $fields[$dst] = (string)$data[$src];
            }
        }

        return $fields;
    }

    private static function syncAddresses(int $requisiteId, array $addresses): void
    {
        foreach ($addresses as $addr) {
            $typeId = self::ADDRESS_TYPE_BY_NAME[$addr['addressType'] ?? ''] ?? null;
            if ($typeId === null) {
                continue;
            }

            EntityAddress::register(
                CCrmOwnerType::Requisite,
                $requisiteId,
                $typeId,
                [
                    'ADDRESS_1' => $addr['street_house'] ?? '',
                    'ADDRESS_2' => $addr['apartment'] ?? '',
                    'CITY' => $addr['settlement'] ?? '',
                    'POSTAL_CODE' => $addr['postalCode'] ?? '',
                    'COUNTRY' => $addr['country'] ?? '',
                    'REGION' => $addr['district'] ?? '',
                    'PROVINCE' => $addr['region'] ?? '',
                ]
            );
        }
    }

    public static function get(array $request): array
    {
        Loader::includeModule('crm');

        $guid  = (string)($request['guid'] ?? '');
        $b24Id = (int)($request['b24_id'] ?? 0);

        // Ищем реквизит
        $r = new EntityRequisite();

        if ($b24Id > 0) {
            $requisiteRow = $r->getList([
                'filter' => ['=ID' => $b24Id],
                'select' => ['ID', 'ENTITY_ID'],
                'limit'  => 1,
            ])->fetch();
        } elseif ($guid !== '') {
            $requisiteRow = $r->getList([
                'filter' => ['=XML_ID' => $guid],
                'select' => ['ID', 'ENTITY_ID'],
                'limit'  => 1,
            ])->fetch();
        } else {
            return self::errorResult('VALIDATION ERROR', 'Необходимо передать b24_id или guid', '');
        }

        if (!$requisiteRow) {
            return self::errorResult('NOT FOUND', 'Контрагент не найден', $guid);
        }

        $companyId['ID'] = (int)$requisiteRow['ENTITY_ID'];

        if ($companyId <= 0) {
            return self::errorResult('NOT FOUND', 'Реквизит не привязан к компании', $guid);
        }

        // Достаём поля компании для билдера
        $companyRow = CompanyTable::getList([
            'select' => [
                '*',
                'UF_CRM_1774519329551',
                'UF_CRM_1774519961417',
                'UF_CRM_COMPANY_3885072309690',
                'UF_CRM_1776097823371',
                'UF_CRM_1776097831202',
                'UF_CRM_1776097840683',
                'UF_CRM_1776097854105',
            ],
            'filter' => ['=ID' => $companyId],
            'limit'  => 1,
        ])->fetch();


        if (!$companyRow) {
            return self::errorResult('NOT FOUND', "Компания {$companyId} не найдена", $guid);
        }

        $fm = ['PHONE' => [], 'EMAIL' => []];
        $dbRes = \CCrmFieldMulti::GetList(['ID' => 'ASC'], ['ENTITY_ID' => 'COMPANY', 'ELEMENT_ID' => $companyId]);
        while ($multi = $dbRes->Fetch()) {
            if (isset($fm[$multi['TYPE_ID']])) {
                $fm[$multi['TYPE_ID']][] = ['VALUE' => $multi['VALUE']];
            }
        }
        $companyRow['FM'] = $fm;

        $data = KontragentBuilder::build($companyRow);

        if (empty($data)) {
            return self::errorResult('BUILD ERROR', 'Не удалось построить ответ контрагента', $guid);
        }

        return [
            'status' => 'success',
            'b24_id' => (int)$requisiteRow['ID'],
            'guid'   => $data['guid'],
            'data'   => $data,
        ];
    }
}