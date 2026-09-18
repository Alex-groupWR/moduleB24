<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use CCrmOwnerType;
use Rusgeocom\Rusgeocom\Exchange\Dto\CompanyDto;
use Rusgeocom\Rusgeocom\Exchange\Services\Builders\KontragentBuilder;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

class CompanyService
{
    use ExchangeHelperTrait;

    private static bool $isSyncFromOneC = false;

    public static function setSyncFromOneC(bool $value): void
    {
        self::$isSyncFromOneC = $value;
    }

    public static function isSyncFromOneC(): bool
    {
        return self::$isSyncFromOneC;
    }

    /**
     * Синхронизация компании (создание или обновление).
     *
     * Валидация обязательных полей (guid, companyName, markDelete)
     * выполняется до вызова — см. CompanyValidate::checkParams()
     * в UpdateCompanyRequestHandler.
     */
    public static function sync(array $data): array
    {
        Loader::includeModule('crm');
        self::setSyncFromOneC(true);

        try {
            $dto = CompanyDto::fromRequest($data, applyDefaultSegment: true);

            [$companyId, $foundBy] = self::findCompanyId($data);

            $factory = Container::getInstance()->getFactory(CCrmOwnerType::Company);
            if (!$factory) {
                return self::errorResult('COMPANY_ERROR', 'Фабрика компании не найдена', $dto->guid);
            }

            $isNew = $companyId === null;
            $item = $isNew ? $factory->createItem() : $factory->getItem($companyId);

            if (!$isNew && !$item) {
                return self::errorResult('COMPANY_ERROR', "Компания {$companyId} не найдена в базе", $dto->guid);
            }

            foreach ($dto->toCrmFields() as $field => $value) {
                if ($item->hasField($field)) {
                    $item->set($field, $value);
                }
            }

            $operation = $isNew
                ? $factory->getAddOperation($item)
                : $factory->getUpdateOperation($item);

            $result = $operation->disableCheckAccess()->disableSaveToHistory()->launch();

            if (!$result->isSuccess()) {
                return self::errorResult(
                    'COMPANY_SAVE_ERROR',
                    'Ошибка сохранения компании: ' . implode(', ', $result->getErrorMessages()),
                    $dto->guid
                );
            }

            $savedId = $item->getId();
            self::updateCompanyMultifields($savedId, $data);

            if (array_key_exists('contacts', $data)) {
                $contacts = is_array($data['contacts']) ? $data['contacts'] : [];
                self::syncCompanyContacts($savedId, $contacts);
            }

            return [
                'status'   => $isNew ? 'created' : 'updated',
                'b24_id'   => $savedId,
                'guid'     => $dto->guid,
            ];
        } finally {
            self::setSyncFromOneC(false);
        }
    }

    /**
     * Поиск компании по b24_id, guid (ORIGIN_ID) или наименованию
     */
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

        if (!empty($data['companyName'])) {
            $row = CompanyTable::getList(['filter' => ['=%TITLE' => $data['companyName']], 'select' => ['ID'], 'limit' => 1])->fetch();
            if ($row) {
                return [(int)$row['ID'], 'company_name'];
            }
        }

        return [null, null];
    }

    /**
     * Обновление множественных полей (Телефон, Email).
     * Не входит в CompanyDto, так как это не UF-поля Item, а отдельная сущность CCrmFieldMulti.
     */
    private static function updateCompanyMultifields(int $companyId, array $data): void
    {
        $mf = new \CCrmFieldMulti();

        $dbRes = \CCrmFieldMulti::GetList([], ['ENTITY_ID' => 'COMPANY', 'ELEMENT_ID' => $companyId]);
        while ($row = $dbRes->Fetch()) {
            $mf->Delete($row['ID']);
        }

        foreach (array_filter(['PHONE' => $data['phone'] ?? null, 'EMAIL' => $data['email'] ?? null]) as $typeId => $value) {
            $mf->Add([
                'ENTITY_ID'  => 'COMPANY',
                'ELEMENT_ID' => $companyId,
                'TYPE_ID'    => $typeId,
                'VALUE_TYPE' => 'WORK',
                'VALUE'      => trim((string)$value),
            ]);
        }
    }

    /**
     * Привязка / отвязка контактов компании
     */
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

    /**
     * Получение информации о компании по b24_id или guid
     */
    public static function get(array $request): array
    {
        Loader::includeModule('crm');

        $guid  = (string)($request['guid'] ?? '');
        $b24Id = (int)($request['b24_id'] ?? 0);

        if ($b24Id <= 0 && $guid === '') {
            return self::errorResult('VALIDATION ERROR', 'Необходимо передать b24_id или guid', '');
        }

        $filter = $b24Id > 0 ? ['=ID' => $b24Id] : ['=ORIGIN_ID' => $guid];

        $companyRow = CompanyTable::getList([
            'select' => [
                '*',
                CompanyDto::FIELD_SEGMENT,
                CompanyDto::FIELD_STATUS_WORK,
                CompanyDto::FIELD_MARK_DELETE,
                CompanyDto::FIELD_IS_BUYER,
                CompanyDto::FIELD_IS_SUPPLIER,
                CompanyDto::FIELD_IS_COMPETITOR,
                CompanyDto::FIELD_IS_OTHER,
            ],
            'filter' => $filter,
            'limit'  => 1,
        ])->fetch();

        if (!$companyRow) {
            return self::errorResult('NOT FOUND', 'Компания не найдена', $guid);
        }

        $companyId = (int)$companyRow['ID'];

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
            return self::errorResult('BUILD ERROR', 'Не удалось построить ответ компании', $guid);
        }

        return [
            'status' => 'success',
            'b24_id' => $companyId,
            'guid'   => $companyRow['ORIGIN_ID'] ?? $guid,
            'data'   => $data,
        ];
    }
}