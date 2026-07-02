<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreTable;
use Bitrix\Main\SystemException;
use Rusgeocom\Rusgeocom\Exchange\ExchangeProtocol;

class WarehouseService
{
    public const GUID_PROPERTY = 'XML_ID';
    public const MARK_DELETE_PROPERTY = 'UF_CAT_STORE_1773312932041';

    public static function init(): void
    {
        if (!Loader::includeModule('catalog')) {
            throw new SystemException('Модуль catalog не установлен');
        }
    }

    public static function checkExistWarehouse(string $warehouseGUID): ?array
    {
        if (empty($warehouseGUID)) {
            return null;
        }
        self::init();

        $store = StoreTable::getList([
            'select' => [
                'b24_id' => 'ID',
                'guid' => self::GUID_PROPERTY
            ],
            'filter' => [
                '=' . self::GUID_PROPERTY => $warehouseGUID
            ],
            'limit' => 1
        ])->fetch();

        return $store ?: null;
    }

    public static function addWarehouse(array $data): array
    {
        self::init();

        $result = StoreTable::add([
            'TITLE' => $data['name'],
            'ADDRESS' => $data['address'] ?? 'Склад без адреса',
            'ACTIVE' => 'Y',
            self::GUID_PROPERTY => $data['guid'],
            self::MARK_DELETE_PROPERTY => $data['markDelete'],
        ]);

        if ($result->isSuccess()) {
            return [
                'b24_id' => (int)$result->getId(),
                'guid' => $data['guid'],
                'status' => 'created'
            ];
        }

        return self::errorResult('ERROR CREATE', 'Ошибка создания склада: ' . implode(', ', $result->getErrorMessages()), $data['guid']);
    }

    public static function updateWarehouse(int $id, array $data): array
    {
        self::init();

        $result = StoreTable::update($id, [
            'TITLE' => $data['name'],
            'ADDRESS' => $data['address'],
            self::MARK_DELETE_PROPERTY => $data['markDelete']
        ]);

        if ($result->isSuccess()) {
            return [
                'b24_id' => $id,
                'guid' => $data['guid'],
                'status' => 'updated'
            ];
        }

        return self::errorResult('ERROR UPDATE', 'Ошибка обновления склада: ' . implode(', ', $result->getErrorMessages()), $data['guid']);
    }

    private static function errorResult(string $statusError, string $msg, string $guid): array
    {
        return [
            ExchangeProtocol::KEY_ERROR => $statusError,
            ExchangeProtocol::KEY_ERROR_TEXT => $msg,
            ExchangeProtocol::GUID => $guid,
        ];
    }

    public static function getWarehouse(array $request): array
    {
        self::init();

        $guid  = (string)($request['guid'] ?? '');
        $b24Id = (int)($request['b24_id'] ?? 0);

        if ($b24Id > 0) {
            $store = StoreTable::getList([
                'select' => ['ID', 'TITLE', 'ADDRESS', self::GUID_PROPERTY, self::MARK_DELETE_PROPERTY],
                'filter' => ['=ID' => $b24Id],
            ])->fetch();
        } elseif ($guid !== '') {
            $store = StoreTable::getList([
                'select' => ['ID', 'TITLE', 'ADDRESS', self::GUID_PROPERTY, self::MARK_DELETE_PROPERTY],
                'filter' => ['=' . self::GUID_PROPERTY => $guid],
            ])->fetch();
        } else {
            return self::errorResult('VALIDATION ERROR', 'Необходимо передать b24_id или guid', '');
        }

        if (!$store) {
            return self::errorResult('NOT FOUND', 'Склад не найден', $guid);
        }

        $data = [
            'name'       => $store['TITLE'],
            'address'    => $store['ADDRESS'],
            'markDelete' => (bool)$store[self::MARK_DELETE_PROPERTY],
        ];

        return [
            'status' => 'success',
            'b24_id' => (int)$store['ID'],
            'guid'   => $store[self::GUID_PROPERTY],
            'data'   => $data,
        ];
    }
}
