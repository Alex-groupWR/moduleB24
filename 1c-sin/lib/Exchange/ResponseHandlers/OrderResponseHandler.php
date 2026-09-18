<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\ResponseHandlers;

use Bitrix\Crm\DealTable;
use Bitrix\Main\Loader;
use Local\Backoffice\ActivityService;
use Rusgeocom\Rusgeocom\Exchange\Services\Notifier\DealActivityNotifier;
use Rusgeocom\Rusgeocom\Exchange\Services\Notifier\OneCLinkNotifier;
use Rusgeocom\Rusgeocom\Exchange\Tables\RusgeocomOrderProductTable;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class OrderResponseHandler implements ResponseHandlerInterface
{
    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    public function handle(array $request, array $response): array
    {
        $this->logger->info('Обработка ответа 1С для заказа', $response);

        Loader::includeModule('crm');

        $errors = [];
        $items = $response['items'] ?? [];

        foreach ($items as $item) {
            $dealId = isset($item['b24_id']) ? (int)$item['b24_id'] : 0;
            $guid   = (string)($item['guid'] ?? '');

            if (!empty($item['error'])) {
                $errors[] = [
                    'entity'  => 'order',
                    'b24_id'  => $dealId,
                    'guid'    => $guid,
                    'message' => $item['error'],
                ];

                DealActivityNotifier::notifyError($dealId, (string)$item['error']);
                continue;
            }

            if ($dealId > 0) {
                DealActivityNotifier::resolveOpenActivity($dealId);
            }

            $error = $this->syncDealOriginId($dealId, $guid);
            if ($error !== null) {
                $errors[] = $error;
                DealActivityNotifier::notifyError($dealId, $error['message']);
            }

            if (!empty($item['products']) && is_array($item['products'])) {
                $error = $this->syncProductLineIds($dealId, $item['products']);
                if ($error !== null) {
                    $errors[] = $error;
                    DealActivityNotifier::notifyError($dealId, $error['message']);
                }
            }

            if (!empty($item['URL1C'])) {
                ActivityService::create1CLog(
                    $dealId,
                    $item['titleActivity']??'Заказ в 1с',
                    (string)$item['URL1C'],
                    \CCrmOwnerType::Deal
                );
            }
        }

        if (!empty($errors)) {
            $this->logger->warning('Ошибки при обработке ответа 1С по заказу', $errors);
        }

        return array_merge($response, ['sync_errors' => $errors]);
    }

    private function syncDealOriginId(int $dealId, string $guid): ?array
    {
        if (empty($guid)) {
            return null;
        }

        $existing = DealTable::getList([
            'select' => ['ID', 'ORIGIN_ID'],
            'filter' => ['=ID' => $dealId],
            'limit'  => 1,
        ])->fetch();

        if (!$existing) {
            return [
                'entity'  => 'deal',
                'message' => "Сделка {$dealId} не найдена",
                'data'    => ['b24_id' => $dealId, 'guid' => $guid],
            ];
        }

        if ($existing['ORIGIN_ID'] === $guid) {
            $this->logger->info("Сделка {$dealId}: ORIGIN_ID уже актуален, пропускаем");
            return null;
        }

        $result = DealTable::update($dealId, ['ORIGIN_ID' => $guid , 'UF_CRM_1787584742450' => true]);

        if (!$result->isSuccess()) {
            return [
                'entity'  => 'deal',
                'message' => 'Ошибка обновления ORIGIN_ID: ' . implode(', ', $result->getErrorMessages()),
                'data'    => ['b24_id' => $dealId, 'guid' => $guid],
            ];
        }

        $this->logger->info("Сделка {$dealId}: ORIGIN_ID обновлён → {$guid}");
        return null;
    }

    /**
     * Синхронизирует lineProductId1c для товарных строк после успешного проведения
     * документа в 1С — 1С может присвоить/подтвердить свои идентификаторы позиций.
     */
    private function syncProductLineIds(int $dealId, array $products): ?array
    {
        $failed = [];

        foreach ($products as $product) {
            $b24LineId = isset($product['lineProductId']) ? (int)$product['lineProductId'] : 0;
            $lineId1c  = (string)($product['lineProductId1c'] ?? '');

            if ($b24LineId <= 0 || $lineId1c === '') {
                continue;
            }

            $row = RusgeocomOrderProductTable::getRow([
                'select' => ['ID'],
                'filter' => ['=DEAL_ID' => $dealId, '=B24_LINE_PRODUCT_ID' => $b24LineId],
            ]);

            if (!$row) {
                continue;
            }

            $result = RusgeocomOrderProductTable::update($row['ID'], [
                'LINE_PRODUCT_ID_1C' => $lineId1c,
            ]);

            if (!$result->isSuccess()) {
                $failed[] = $b24LineId;
            }
        }

        if (!empty($failed)) {
            return [
                'entity'  => 'order_products',
                'message' => 'Не удалось обновить lineProductId1c для строк: ' . implode(', ', $failed),
                'data'    => ['deal_id' => $dealId],
            ];
        }

        return null;
    }
}