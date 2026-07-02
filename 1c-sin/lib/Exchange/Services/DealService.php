<?php


declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealDirectionEnum;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealStageEnum;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

class DealService
{
    use ExchangeHelperTrait;

    // -----------------------------------------------------------------
    // UF-поля сделки (замените на реальные коды вашего портала)
    // -----------------------------------------------------------------
    private const UF_NUMBER_KP = 'UF_CRM_1732689595485';
    private const UF_POSTPONEMENT = 'UF_CRM_1725531407275';
    private const UF_NUMBER_1C = 'UF_CRM_DEAL_3862607531962';
    private const UF_ONLINE_ORDER = 'UF_CRM_DEAL_3859045917590';
    private const UF_DATE_RESERVATION = 'UF_CRM_DEAL_3859045919152';
    private const UF_DIRECTION = 'CATEGORY_ID';
    private const UF_FILES = 'UF_CRM_1730983506502';

    // Привязки смарт-процессов к сделке
    private const PARENT_AGREEMENT = 'UF_CRM_1726999670';
    private const PARENT_BUSINESS_REGION = 'UF_CRM_1724321615';
    private const PARENT_ORGANISATION = 'UF_CRM_1776192243';
    private const PARENT_DELIVERY = 'UF_CRM_1776192264';

    // -----------------------------------------------------------------
    // Публичный API
    // -----------------------------------------------------------------

    public static function sync(array $request): array
    {
        Loader::includeModule('crm');

        $guid = (string)($request['guid'] ?? '');

        $dealId = self::findDealId($request);
        $isNew = $dealId === null;

        $factory = self::getFactory();

        $item = $isNew
            ? $factory->createItem()
            : $factory->getItem($dealId);

        if (!$isNew && !$item) {
            return self::errorResult('DEAL_ERROR', "Сделка {$dealId} не найдена в базе", $guid);
        }

        foreach (self::buildFields($request) as $field => $value) {
            if ($item->hasField($field)) {
                $item->set($field, $value);
            }
        }

        $operation = $isNew
            ? $factory->getAddOperation($item)
            : $factory->getUpdateOperation($item);

        $result = $operation->disableCheckAccess()->launch();

        if (!$result->isSuccess()) {
            return self::errorResult(
                'DEAL_SAVE_ERROR',
                implode(', ', $result->getErrorMessages()),
                $guid
            );
        }

        $savedId = (int)$item->getId();

        self::syncContacts($savedId, $request['client']['contacts'] ?? []);

        if (!empty($request['files'])) {

            self::attachFiles($savedId, $request['files']);
        }

        return self::successResult($savedId,$guid,$isNew ? 'created' : 'updated');
    }

    // -----------------------------------------------------------------
    // Поиск существующей сделки
    // -----------------------------------------------------------------

    private static function findDealId(array $data): ?int
    {
        if (!empty($data['b24_id'])) {
            $row = DealTable::getList([
                'filter' => ['=ID' => (int)$data['b24_id']],
                'select' => ['ID'],
            ])->fetch();

            if ($row) {
                return (int)$row['ID'];
            }
        }

        if (!empty($data['guid'])) {
            $row = DealTable::getList([
                'filter' => ['=ORIGIN_ID' => $data['guid']],
                'select' => ['ID'],
            ])->fetch();

            if ($row) {
                return (int)$row['ID'];
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Сборка полей сделки
    // -----------------------------------------------------------------

    private static function buildFields(array $data): array
    {
        $fields = [
            'ORIGIN_ID' => $data['guid'] ?? '',
            'ASSIGNED_BY_ID' => self::resolveManagerId($data['manager'] ?? []),
            'COMMENTS' => $data['comments'] ?? '',
        ];

        $direction = !empty($data['direction'])
            ? DealDirectionEnum::tryFrom($data['direction'])
            : null;

        if ($direction) {
            // Получаем ID направления для Bitrix24
            $fields[self::UF_DIRECTION] = $direction->getB24EnumId();

            // 2. Теперь определяем Стадию по тексту, НО только в рамках этого направления
            if (!empty($data['stage'])) {
                $stage = $direction->getStageFromLabel($data['stage']);

                if ($stage) {
                    $fields['STAGE_ID'] = $stage->value;
                }
            }
        }

        // Компания клиента
        $companyId = self::resolveEntityId($data['client']['company'] ?? [], EntityType::COMPANY);
        if ($companyId !== null) {
            $fields['COMPANY_ID'] = $companyId;
        }

        $fields += self::buildScalarUfFields($data);
        $fields += self::buildParentFields($data);

        return array_filter($fields, fn($v) => $v !== null && $v !== '');
    }

    private static function buildScalarUfFields(array $data): array
    {
        $map = [
            'numberKp' => self::UF_NUMBER_KP,
            'postponement' => self::UF_POSTPONEMENT,
            'number1C' => self::UF_NUMBER_1C,
            'onlineOrderNumber' => self::UF_ONLINE_ORDER,
            'dateReservation' => self::UF_DATE_RESERVATION,
        ];

        $result = [];
        foreach ($map as $srcKey => $ufField) {
            if (isset($data[$srcKey]) && $data[$srcKey] !== '') {
                $result[$ufField] = $data[$srcKey];
            }
        }

        return $result;
    }

    private static function buildParentFields(array $data): array
    {
        $bindings = [
            'typeAgreement' => [self::PARENT_AGREEMENT, EntityType::SMART_PROCESS_AGREEMENT_TYPE],
            'businessRegion' => [self::PARENT_BUSINESS_REGION, EntityType::SMART_PROCESS_BUSINESS_REGION],
            'organisation' => [self::PARENT_ORGANISATION, EntityType::SMART_PROCESS_ORGANISATION],
            'methodDelivery' => [self::PARENT_DELIVERY, EntityType::SMART_PROCESS_METHOD_DELIVERY],
        ];

        $result = [];
        foreach ($bindings as $dataKey => [$parentField, $entityType]) {
            if (!empty($data[$dataKey])) {
                $id = self::resolveEntityId($data[$dataKey], $entityType);
                if ($id !== null) {
                    $result[$parentField] = $id;
                }
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // Контакты сделки
    // -----------------------------------------------------------------

    private static function syncContacts(int $dealId, array $contacts): void
    {
        $newIds = self::resolveContactIds($contacts);
        $existingIds = self::fetchBoundContactIds($dealId);

        $toUnbind = array_values(array_diff($existingIds, $newIds));
        $toBind = array_values(array_diff($newIds, $existingIds));

        if (!empty($toUnbind)) {
            DealContactTable::unbindContacts(
                $dealId,
                array_map(fn(int $id) => ['CONTACT_ID' => $id], $toUnbind)
            );
        }

        if (!empty($toBind)) {
            DealContactTable::bindContacts(
                $dealId,
                array_map(fn(int $id) => ['CONTACT_ID' => $id, 'IS_PRIMARY' => 'N', 'SORT' => 10], $toBind)
            );
        }
    }

    private static function fetchBoundContactIds(int $dealId): array
    {
        return array_map(
            'intval',
            array_column(
                DealContactTable::getList([
                    'filter' => ['=DEAL_ID' => $dealId],
                    'select' => ['CONTACT_ID'],
                ])->fetchAll(),
                'CONTACT_ID'
            )
        );
    }

    private static function resolveContactIds(array $contacts): array
    {
        $ids = [];
        foreach ($contacts as $contact) {
            if (!empty($contact['b24_id'])) {
                $ids[] = (int)$contact['b24_id'];
            } elseif (!empty($contact['guid'])) {
                $id = ContactService::getExistId($contact['guid']);
                if ($id !== false) {
                    $ids[] = $id;
                }
            }
        }

        return array_unique($ids);
    }


    private static function attachFiles(int $dealId, array $files): void
    {
        $fileArrays = array_values(array_filter(
            array_map(static fn(array $file) => self::makeFileArray($file), $files)
        ));

        if (empty($fileArrays)) {
            return;
        }


        $fields = [
            self::UF_FILES => $fileArrays
        ];

        $crmDeal = new \CCrmDeal(false);
        $result = $crmDeal->Update(
            $dealId,
            $fields,
            true,
            true,
            ['REGISTER_SONET_EVENT' => false, 'DISABLE_USER_FIELD_CHECK' => true]
        );
    }

    private static function makeFileArray(array $file): ?array
    {
        if (empty($file['fileBase64']) || empty($file['fileName'])) {
            return null;
        }

        return [
            'fileData' => [
                $file['fileName'],
                $file['fileBase64'] // Передаем строку как есть
            ]
        ];
    }

    private static function resolveManagerId(array $manager): int
    {
        if (!empty($manager['b24_id']) && $manager['b24_id'] > 0) {
            return (int)$manager['b24_id'];
        }

        if (!empty($manager['guid'])) {
            return SearchEntityService::searchUser($manager['guid']) ?? 1;
        }

        return 1;
    }

    private static function resolveEntityId(array $data, EntityType $type): ?int
    {
        if (!empty($data['b24_id']) && $data['b24_id'] > 0) {
            return (int)$data['b24_id'];
        }

        if (!empty($data['guid'])) {
            return SearchEntityService::searchByGuid($data['guid'], $type);
        }

        return null;
    }

    private static function getFactory(): Factory
    {
        $factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);

        if (!$factory) {
            throw new \RuntimeException('Фабрика сделок (CCrmOwnerType::Deal) не найдена');
        }

        return $factory;
    }
}