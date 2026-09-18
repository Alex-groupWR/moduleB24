<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Main\Loader;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Catalog\StoreTable;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

class StockService
{
    use ExchangeHelperTrait;

    private const IBLOCK_ID = 14;
    private const PRODUCT_GUID_FIELD = 'XML_ID';

    public static function sync(array $request): array
    {
        Loader::includeModule('catalog');
        Loader::includeModule('iblock');

        $guid = (string)($request['guid'] ?? '');

        // Находим ID основного товара и ID предложения (если есть)
        $result = self::resolveProductAndOfferIds($guid);
        if (!$result['productId']) {
            return self::errorResult('PRODUCT_NOT_FOUND', "Товар с guid {$guid} не найден", $guid);
        }

        $storeId = self::resolveWarehouseId($request['warehouse'] ?? []);
        if (!$storeId) {
            return self::errorResult('WAREHOUSE_NOT_FOUND', 'Склад не найден', $guid);
        }

        $quantity = self::parseDecimal((string)($request['quantity'] ?? '0'));

        // Используем ID предложения для обновления остатков, если оно есть
        $targetProductId = $result['offerId'] ?: $result['productId'];

        $existing = StoreProductTable::getList([
            'select' => ['ID'],
            'filter' => ['=PRODUCT_ID' => $targetProductId, '=STORE_ID' => $storeId],
            'limit'  => 1,
        ])->fetch();

        $resultOperation = $existing
            ? StoreProductTable::update($existing['ID'], ['AMOUNT' => $quantity])
            : StoreProductTable::add([
                'PRODUCT_ID' => $targetProductId,
                'STORE_ID'   => $storeId,
                'AMOUNT'     => $quantity,
            ]);

        if (!$resultOperation->isSuccess()) {
            return self::errorResult('REST_SAVE_ERROR', implode(', ', $resultOperation->getErrorMessages()), $guid);
        }

        // Обновляем количество для основного товара
        self::recalcTotalQuantity($result['productId']);

        // Возвращаем ID основного товара
        return self::successResult($result['productId'], $guid, 'synced');
    }

    private static function resolveProductAndOfferIds(string $guid): array
    {
        if (!$guid) {
            return ['productId' => 0, 'offerId' => 0];
        }

        // Сначала ищем товар
        $row = ElementTable::getRow([
            'select' => ['ID'],
            'filter' => ['IBLOCK_ID' => self::IBLOCK_ID, '=' . self::PRODUCT_GUID_FIELD => $guid],
        ]);

        if (!$row) {
            return ['productId' => 0, 'offerId' => 0];
        }

        $productId = (int)$row['ID'];
        $offerId = 0;

        // Проверяем тип товара
        $catalogProduct = \CCatalogProduct::GetByID($productId);
        if ($catalogProduct && $catalogProduct['TYPE'] == 3) {
            // Если это товар с предложениями, ищем предложение с таким же GUID
            $offerId = self::findOfferByGuid($productId, $guid);

            // Если предложение не найдено, берем первое доступное предложение
            if (!$offerId) {
                $offers = \CCatalogSKU::getOffersList($productId, self::IBLOCK_ID);
                if (!empty($offers[$productId])) {
                    $firstOffer = reset($offers[$productId]);
                    $offerId = (int)$firstOffer['ID'];
                }
            }
        }

        return [
            'productId' => $productId,
            'offerId' => $offerId
        ];
    }

    private static function findOfferByGuid(int $productId, string $guid): int
    {
        $offers = \CCatalogSKU::getOffersList(
            $productId,
            self::IBLOCK_ID,
            [],
            ['ID', 'XML_ID']
        );

        if (empty($offers[$productId])) {
            return 0;
        }

        foreach ($offers[$productId] as $offer) {
            if ($offer['XML_ID'] == $guid) {
                return (int)$offer['ID'];
            }
        }

        return 0;
    }

    private static function recalcTotalQuantity(int $productId): void
    {
        $total = 0.0;
        $rows = StoreProductTable::getList([
            'select' => ['AMOUNT'],
            'filter' => ['=PRODUCT_ID' => $productId],
        ]);
        while ($row = $rows->fetch()) {
            $total += (float)$row['AMOUNT'];
        }

        $catalogProduct = \CCatalogProduct::GetByID($productId);

        if (!$catalogProduct) {
            // Товар ещё не зарегистрирован в каталоге (нет записи в b_catalog_product) —
            // Update() в этом случае ничего не делает, поэтому создаём запись явно
            \CCatalogProduct::Add([
                'ID' => $productId,
                'TYPE' => \CCatalogProduct::TYPE_PRODUCT,
                'QUANTITY' => $total,
            ]);
            return;
        }

        \CCatalogProduct::Update($productId, ['QUANTITY' => $total]);
    }

    private static function resolveProductId(string $guid): int
    {
        if (!$guid) {
            return 0;
        }

        $row = ElementTable::getRow([
            'select' => ['ID'],
            'filter' => ['IBLOCK_ID' => self::IBLOCK_ID, '=' . self::PRODUCT_GUID_FIELD => $guid],
        ]);

        return $row ? (int)$row['ID'] : 0;
    }

    private static function resolveWarehouseId(array $warehouse): int
    {
        if (!empty($warehouse['b24_id'])) {
            return (int)$warehouse['b24_id'];
        }

        if (!empty($warehouse['guid'])) {
            $row = StoreTable::getRow([
                'select' => ['ID'],
                'filter' => ['=' . \Rusgeocom\Rusgeocom\Exchange\Services\WarehouseService::GUID_PROPERTY => $warehouse['guid']],
            ]);
            return $row ? (int)$row['ID'] : 0;
        }

        return 0;
    }

    private static function parseDecimal(string $value): float
    {
        $clean = str_replace(',', '.', $value);
        $clean = preg_replace('/[^0-9.\-]/', '', $clean);
        return (float)$clean;
    }
}