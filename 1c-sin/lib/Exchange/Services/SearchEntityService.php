<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Main\Loader;
use Bitrix\Main\UserTable;
use Bitrix\Crm\CompanyTable;
use Bitrix\Crm\ContactTable;
use Bitrix\Crm\Service\Container;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;

class SearchEntityService
{
    private const COMPANY_GUID_FIELD = 'ORIGIN_ID';
    private const CONTACT_GUID_FIELD = 'ORIGIN_ID';
    private const USER_GUID_FIELD = 'XML_ID';
    private const SMART_PROCESS_GUID_FIELD = 'XML_ID';

    private static ?Container $crmContainer = null;

    private static function getCrmContainer(): Container
    {
        if (self::$crmContainer === null) {
            Loader::includeModule('crm');
            self::$crmContainer = Container::getInstance();
        }
        return self::$crmContainer;
    }

    public static function searchByGuid(string $guid, EntityType $type): ?int
    {
        return match($type) {
            EntityType::COMPANY => self::searchCompany($guid),
            EntityType::CONTACT => self::searchContact($guid),
            EntityType::USER => self::searchUser($guid),
            default => self::searchSmartProcess($guid, $type),
        };
    }

    public static function searchCompany(string $guid): ?int
    {
        return self::getIdByFilter(CompanyTable::class, [self::COMPANY_GUID_FIELD => $guid]);
    }

    public static function searchContact(string $guid): ?int
    {
        return self::getIdByFilter(ContactTable::class, [self::CONTACT_GUID_FIELD => $guid]);
    }

    public static function searchUser(string $guid): ?int
    {
        return self::getIdByFilter(UserTable::class, [self::USER_GUID_FIELD => $guid]);
    }

    public static function searchSmartProcess(string $guid, EntityType $type): ?int
    {
        $factoryId = $type->getFactoryId();
        if ($factoryId === null) {
            return null;
        }

        $factory = self::getCrmContainer()->getFactory($factoryId);
        if (!$factory) {
            return null;
        }

        $items = $factory->getItems([
            'select' => ['ID'],
            'filter' => ['=' . self::SMART_PROCESS_GUID_FIELD => $guid],
            'limit' => 1,
        ]);

        $first = reset($items);
        return $first ? $first->getId() : null;
    }

    public static function getGuidByIdSP(int $id, EntityType $type): ?string
    {
        $factoryId = $type->getFactoryId();
        if ($factoryId === null) {
            return null;
        }

        $factory = self::getCrmContainer()->getFactory($factoryId);
        if (!$factory) {
            return null;
        }

        $items = $factory->getItems([
            'select' => [self::SMART_PROCESS_GUID_FIELD],
            'filter' => ['=ID' => $id],
            'limit' => 1,
        ]);

        $first = reset($items);
        return $first ? $first[self::SMART_PROCESS_GUID_FIELD] : null;
    }

    private static function getIdByFilter(string $entityTableClass, array $filter): ?int
    {
        $row = $entityTableClass::getList([
            'select' => ['ID'],
            'filter' => $filter,
            'limit' => 1,
        ])->fetch();

        return $row ? (int)$row['ID'] : null;
    }

    public static function getGuidById(int $id, EntityType $type): ?string
    {
        return match($type) {
            EntityType::COMPANY => self::getCompanyGuid($id),
            EntityType::CONTACT => self::getContactGuid($id),
            EntityType::USER    => self::getUserGuid($id),
            default             => self::getSmartProcessGuid($id, $type),
        };
    }

    public static function getCompanyGuid(int $id): ?string
    {
        return self::getFieldByFilter(CompanyTable::class, ['=ID' => $id], self::COMPANY_GUID_FIELD);
    }

    public static function getContactGuid(int $id): ?string
    {
        return self::getFieldByFilter(ContactTable::class, ['=ID' => $id], self::CONTACT_GUID_FIELD);
    }

    public static function getUserGuid(int $id): ?string
    {
        return self::getFieldByFilter(UserTable::class, ['=ID' => $id], self::USER_GUID_FIELD);
    }

    public static function getSmartProcessGuid(int $id, EntityType $type): ?string
    {
        $factoryId = $type->getFactoryId();
        if ($factoryId === null) {
            return null;
        }

        $factory = self::getCrmContainer()->getFactory($factoryId);
        if (!$factory) {
            return null;
        }

        $items = $factory->getItems([
            'select' => ['ID', self::SMART_PROCESS_GUID_FIELD],
            'filter' => ['=ID' => $id],
            'limit'  => 1,
        ]);

        $first = reset($items);
        return $first ? (string)$first->get(self::SMART_PROCESS_GUID_FIELD) : null;
    }

    private static function getFieldByFilter(string $entityTableClass, array $filter, string $field): ?string
    {
        $row = $entityTableClass::getList([
            'select' => [$field],
            'filter' => $filter,
            'limit'  => 1,
        ])->fetch();

        return ($row && !empty($row[$field])) ? (string)$row[$field] : null;
    }
}