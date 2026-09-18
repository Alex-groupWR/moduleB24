<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;
use Rusgeocom\Rusgeocom\Exchange\ExchangeService;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerInterface;
use Throwable;

class StoreAmountService
{
    private const ACTION = 'get_store_amount';
    private const ONE_C_CHUNK_SIZE = 200; // на один SOAP-вызов, чтобы не разово гнать тысячи позиций

    private static ?LoggerInterface $logger = null;

    /**
     * Всегда идём в 1С за остатками, никакого кэша/таблицы не используем.
     *
     * @param int[]  $productIds
     * @param string $warehouseGuid GUID склада (XML_ID смарт-процесса "Склад")
     * @return array<int, array{amount:float, reserveAmount:float, result:string}>
     *   ключ — b24_id товара
     */
    public static function getAmounts(array $productIds, string $warehouseGuid): array
    {
        $productIds = array_values(array_unique(array_filter($productIds, 'is_numeric')));
        if (empty($productIds) || $warehouseGuid === '') {
            return [];
        }

        Loader::includeModule('iblock');

        $guidByProductId = self::resolveProductGuids($productIds);
        if (empty($guidByProductId)) {
            return [];
        }

        $uniqueGuids = array_values(array_unique($guidByProductId));
        $amountsByGuid = self::fetchFromOneC($uniqueGuids, $warehouseGuid);

        $result = [];
        foreach ($guidByProductId as $productId => $guid) {
            if (isset($amountsByGuid[$guid])) {
                $result[$productId] = $amountsByGuid[$guid];
            }
        }

        return $result;
    }

    private static function resolveProductGuids(array $productB24Ids): array
    {
        $rows = ElementTable::getList([
            'select' => ['ID', 'XML_ID'],
            'filter' => ['@ID' => $productB24Ids],
        ])->fetchAll();

        $map = [];
        foreach ($rows as $row) {
            if (!empty($row['XML_ID'])) {
                $cleanGuid = strtok($row['XML_ID'], '#');

                $map[(int)$row['ID']] = $cleanGuid;
            }
        }

        return $map;
    }

    /**
     * Запрашивает остатки в 1С для набора GUID на конкретном складе.
     * Бьёт запрос на чанки, чтобы не гнать тысячи позиций одним SOAP-вызовом.
     *
     * @param string[] $productGuids
     * @return array<string, array{amount:float, reserveAmount:float, result:string}> ключ — GUID товара
     */
    private static function fetchFromOneC(array $productGuids, string $warehouseGuid): array
    {
        $result = [];

        foreach (array_chunk($productGuids, self::ONE_C_CHUNK_SIZE) as $chunk) {
            $items = array_map(
                static fn(string $guid) => ['id' => $guid, 'stores_id' => $warehouseGuid],
                $chunk
            );

            try {
                $response = ExchangeService::send('SyncPacket', [[
                    'entity_type' => self::ACTION,
                    'items' => $items,
                ]]);
            } catch (Throwable $exc) {
                self::getLogger()->exception($exc, 'Ошибка запроса остатков (get_store_amount)', ['items' => $items]);
                continue;
            }

            $normalized = self::normalizeResponse($response);

            foreach ($normalized as $guid => $entry) {
                $store = $entry['stores'][0] ?? null;

                $result[$guid] = [
                    'amount' => $store['amount'] ?? 0.0,
                    'reserveAmount' => $store['reserveAmount'] ?? 0.0,
                    'result' => $entry['result'],
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<string, array{result:string, stores: array<array{store_id:string, store_uid:string, amount:float, reserveAmount:float}>}>
     *   ключ — GUID товара
     */
    private static function normalizeResponse(array $response): array
    {
        $block = $response[0] ?? $response;
        $items = $block['items'] ?? [];

        $result = [];
        foreach ($items as $item) {
            $guid = (string)($item['id'] ?? '');
            if ($guid === '') {
                continue;
            }

            $stores = [];
            foreach ($item['stores'] ?? [] as $store) {
                $stores[] = [
                    'store_id' => (string)($store['store_id'] ?? ''),
                    'store_uid' => (string)($store['store_uid'] ?? ''),
                    'amount' => (float)($store['amount'] ?? 0),
                    'reserveAmount' => (float)($store['reserveAmount'] ?? 0),
                ];
            }

            $result[$guid] = [
                'result' => (string)($item['result'] ?? ''),
                'stores' => $stores,
            ];
        }

        return $result;
    }

    private static function getLogger(): LoggerInterface
    {
        return self::$logger ??= LoggerFactory::get(static::class);
    }
}