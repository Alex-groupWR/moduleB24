<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services\Builders;

use Bitrix\Crm\AddressTable;
use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\ContactTable;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use CCrmOwnerType;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\Enum\SegmentEnum;
use Rusgeocom\Rusgeocom\Exchange\Services\SearchEntityService;
use Rusgeocom\Rusgeocom\Exchange\Validate\KontragentValidate;

class KontragentBuilder
{
    public const SEGMENT_FIELD          = 'UF_CRM_1774519329551';
    private const STATUS_WORK_FIELD      = 'UF_CRM_1774519961417';
    private const MARK_DELETE_FIELD      = 'UF_CRM_COMPANY_3885072309690';
    private const IS_BUYER_FIELD         = 'UF_CRM_1776097823371';
    private const IS_SUPPLIER_FIELD      = 'UF_CRM_1776097831202';
    private const IS_COMPETITOR_FIELD    = 'UF_CRM_1776097840683';
    private const IS_OTHER_FIELD         = 'UF_CRM_1776097854105';
    public const BUSINESS_REGION_FIELD  = 'PARENT_ID_1032';

    private const REQUISITE_MARK_DELETE_FIELD = 'UF_CRM_1774516144';
    private const IS_WHOLESALER_FIELD         = 'UF_CRM_1776109744';

    private const ADDRESS_TYPE_MAP = [
        EntityAddressType::Registered => 'Юридический адрес',
        EntityAddressType::Primary     => 'Фактический адрес',
    ];

    // Индексы соответствуют PRESET_ID (начинается с 1)
    private const PRESET_BY_ID = [
        1 => 'Организация',
        2 => 'Индивидуальный предприниматель',
        3 => 'Физическое лицо',
        4 => 'Юр. лицо (нерезидент)',
    ];

    private const PRESET_FIS_LICO = 'Физическое лицо';

    public static function build(array $fields): array
    {
        Loader::includeModule('crm');

        $companyId = (int)$fields['ID'];

        $requisiteData = self::fetchRequisiteData($companyId);

        if (!KontragentValidate::check($fields, $requisiteData)) {
            return [];
        }

        if (empty($requisiteData)) {
            return [];
        }


        $presetId   = (int)($requisiteData['PRESET_ID'] ?? 0);
        $presetName = self::PRESET_BY_ID[$presetId];

        $inn  = (string)($requisiteData['RQ_INN'] ?? '');
        $kpp  = (string)($requisiteData['RQ_KPP'] ?? '');

        if (
            $inn === ''
            && $kpp === ''
            && $presetName !== self::PRESET_FIS_LICO
        ) {
            return [];
        }



        return [
            'guid'           => (string)$requisiteData['XML_ID'],
            'b24_id'         => (int)$requisiteData['ID'],
            'companyName'    => $requisiteData['RQ_COMPANY_NAME'],
            'companyFullName'=> $requisiteData['RQ_COMPANY_FULL_NAME'],
            'preset'         => $presetName,
            'markDelete'     => self::boolVal($requisiteData[self::REQUISITE_MARK_DELETE_FIELD] ?? false),
            'isWholesaler'   => self::boolVal($requisiteData[self::IS_WHOLESALER_FIELD] ?? false),
            'INN'            => $inn,
            'RQ_KPP'         => $kpp,
            'RQ_OGRN'        => (string)($requisiteData['RQ_OGRN'] ?? ''),
            'company'        => self::buildCompanySection($fields, $companyId),
            'address'        => self::buildAddresses((int)$requisiteData['ID']),
            'contacts'       => self::buildContacts($companyId),
        ];
    }

    private static function buildCompanySection(array $fields, int $companyId): array
    {
        $managerId        = (int)($fields['ASSIGNED_BY_ID'] ?? 1);
        $businessRegionId = (int)($fields[self::BUSINESS_REGION_FIELD] ?? 0);

        $company = [
            'b24_id'      => $companyId,
            'guid'        => (string)($fields['ORIGIN_ID'] ?? ''),
            'companyName' => $fields['TITLE'] ?? '',
            'manage_id'   => [
                'b24_id' => $managerId,
                'guid'   => self::fetchUserGuid($managerId),
            ],
            'segment'      => SegmentEnum::getTextById((int)($fields[self::SEGMENT_FIELD] ?? 0)),
            'statusWork'   => self::boolVal($fields[self::STATUS_WORK_FIELD] ?? false),
            'markDelete'   => self::boolVal($fields[self::MARK_DELETE_FIELD] ?? false),
            'isBuyer'      => self::boolVal($fields[self::IS_BUYER_FIELD] ?? false),
            'isSupplier'   => self::boolVal($fields[self::IS_SUPPLIER_FIELD] ?? false),
            'isCompetitor' => self::boolVal($fields[self::IS_COMPETITOR_FIELD] ?? false),
            'isOther'      => self::boolVal($fields[self::IS_OTHER_FIELD] ?? false),
            'phone'        => self::extractFirstMultiValue($fields, 'PHONE'),
            'email'        => self::extractFirstMultiValue($fields, 'EMAIL'),
        ];

        if ($businessRegionId > 0) {
            $company['businessRegion_id'] = [
                'b24_id' => $businessRegionId,
                'guid'   => SearchEntityService::getGuidByIdSP($businessRegionId, EntityType::SMART_PROCESS_BUSINESS_REGION),
            ];
        }

        return $company;
    }

    private static function buildAddresses(int $requisiteId): array
    {
        $res = AddressTable::getList([
            'filter' => [
                '=ENTITY_TYPE_ID' => \CCrmOwnerType::Requisite, // важно: фильтр по типу сущности
                '=ENTITY_ID'      => $requisiteId,
            ],
        ]);

        $addresses = [];
        while ($row = $res->fetch()) {
            $typeId = (int)$row['TYPE_ID'];
            if (!isset(self::ADDRESS_TYPE_MAP[$typeId])) {
                continue;
            }

            $parts = array_filter([
                $row['POSTAL_CODE'],
                $row['COUNTRY'],
                $row['PROVINCE'],
                $row['REGION'],
                $row['CITY'],
                $row['ADDRESS_1'],
                $row['ADDRESS_2']
            ]);

            $addresses[] = [
                'addressType' => self::ADDRESS_TYPE_MAP[$typeId],
                'address' => implode(', ', $parts), // Склеенная строка
            ];
        }

        return $addresses;
    }

    private static function fetchRequisiteData(int $companyId): array
    {
        $row = (new EntityRequisite())->getList([
            'filter' => [
                '=ENTITY_TYPE_ID' => CCrmOwnerType::Company,
                '=ENTITY_ID'      => $companyId,
            ],
            'select' => [
                'ID', 'XML_ID', 'PRESET_ID',
                'RQ_INN', 'RQ_KPP', 'RQ_OGRN',
                'RQ_COMPANY_NAME', 'RQ_COMPANY_FULL_NAME',
                'RQ_LAST_NAME', 'RQ_FIRST_NAME','RQ_SECOND_NAME',
                self::REQUISITE_MARK_DELETE_FIELD,
                self::IS_WHOLESALER_FIELD,
            ],
            'order' => [
                'ID'   => 'DESC'
            ],
            'limit' => 1,
        ])->fetch();

        return $row ?: [];
    }

    private static function buildContacts(int $companyId): array
    {
        $rows = ContactCompanyTable::getList([
            'filter' => ['=COMPANY_ID' => $companyId],
            'select' => ['CONTACT_ID'],
        ])->fetchAll();

        $contacts = [];
        foreach ($rows as $row) {
            $contactId  = (int)$row['CONTACT_ID'];
            $contactRow = ContactTable::getList([
                'filter' => ['=ID' => $contactId],
                'select' => ['ID', 'ORIGIN_ID', 'NAME', 'LAST_NAME', 'SECOND_NAME'],
                'limit'  => 1,
            ])->fetch();

            if (!$contactRow) {
                continue;
            }

            $phones = [];
            $emails = [];

            $resMulti = \CCrmFieldMulti::GetList(
                ['ID' => 'asc'],
                ['ENTITY_ID' => 'CONTACT', 'ELEMENT_ID' => $contactId]
            );

            while ($multi = $resMulti->Fetch()) {
                match ($multi['TYPE_ID']) {
                    'PHONE' => $phones[] = $multi['VALUE'],
                    'EMAIL' => $emails[] = $multi['VALUE'],
                    default => null,
                };
            }

            $contacts[] = [
                'b24_id'     => $contactId,
                'guid'       => $contactRow['ORIGIN_ID'] ?? '',
                'firstName'  => $contactRow['NAME'] ?? '',
                'lastName'   => $contactRow['LAST_NAME'] ?? '',
                'patronymic' => $contactRow['SECOND_NAME'] ?? '',
                'phones'     => $phones,
                'emails'     => $emails,
            ];
        }

        return $contacts;
    }

    private static function fetchUserGuid(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $row = UserTable::getList([
            'filter' => ['=ID' => $userId],
            'select' => ['XML_ID'],
            'limit'  => 1,
        ])->fetch();

        return $row ? (string)$row['XML_ID'] : '';
    }

    /**
     * Безопасно достаёт первое значение из FM (PHONE/EMAIL)
     */
    private static function extractFirstMultiValue(array $fields, string $type): string
    {
        $values = $fields['FM'][$type] ?? [];
        if (empty($values)) {
            return '';
        }
        $first = reset($values);
        return (string)($first['VALUE'] ?? '');
    }

    private static function boolVal(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, ['Y', true, 1, '1'], true);
    }
}