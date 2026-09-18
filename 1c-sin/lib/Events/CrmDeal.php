<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Events;

use Rusgeocom\Rusgeocom\Exchange\DeleteGuard\DeleteGuard;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealDirectionEnum;
use Rusgeocom\Rusgeocom\Exchange\ExchangeProtocol;
use Rusgeocom\Rusgeocom\Exchange\ExchangeService;
use Rusgeocom\Rusgeocom\Exchange\Messages\MessageProcessor;
use Rusgeocom\Rusgeocom\Exchange\Services\DealStageService;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerInterface;
use Bitrix\Crm\DealTable;
use CCrmOwnerType;
use Throwable;

class CrmDeal
{
    private const MARK_DELETE_FIELD = 'UF_CRM_DEAL_3862607531946';
    private const ACTION_STAGE = 'OrderStage';

    private static array $prevById = [];
    private static ?LoggerInterface $logger = null;

    public static function onBeforeCrmDealDelete($id): bool
    {
        return !DeleteGuard::guard(CCrmOwnerType::Deal, (int)$id);
    }

    public static function onBeforeCrmDealUpdate(array &$fields): void
    {
        $id = (int)($fields['ID'] ?? 0);
        if ($id <= 0 || !isset($fields['STAGE_ID']) || isset(self::$prevById[$id])) {
            return;
        }

        self::$prevById[$id] = DealStageService::getStageSnapshot($id);
    }

    public static function onAfterCrmDealUpdate(array $fields): bool
    {
        if (DealStageService::isSyncFromOneC()) {
            return true;
        }

        $id = (int)($fields['ID'] ?? 0);
        $prev = self::$prevById[$id] ?? null;
        unset(self::$prevById[$id]);

        if ($id <= 0 || !isset($fields['STAGE_ID']) || $prev === null) {
            return true;
        }

        if (($prev['STAGE_ID'] ?? null) === $fields['STAGE_ID']) {
            return true;
        }

        $fields['ORIGIN_ID'] = $fields['ORIGIN_ID'] ?? ($prev['ORIGIN_ID'] ?? '');
        $fields['CATEGORY_ID'] = (int)($fields['CATEGORY_ID'] ?? ($prev['CATEGORY_ID'] ?? 0));

        if (empty($fields['ORIGIN_ID'])) {
            return true;
        }

        // 1. Получаем направление по ID категории Битрикс24
        $direction = DealDirectionEnum::fromB24EnumId($fields['CATEGORY_ID']);
        if ($direction === null) {
            self::getLogger()->warning('Неизвестное направление сделки (CATEGORY_ID)', [
                'deal_id' => $id,
                'category_id' => $fields['CATEGORY_ID'],
            ]);
            return true;
        }

        // 2. Получаем строковые названия направления и стадии для 1С
        $directionName = $direction->value;
        $stageName = $direction->getLabelFromStageValue($fields['STAGE_ID']);

        if ($stageName === null) {
            self::getLogger()->warning('Не удалось сопоставить стадию для направления', [
                'deal_id' => $id,
                'stage_id' => $fields['STAGE_ID'],
                'direction' => $directionName,
            ]);
            return true;
        }

        $payload = [
            'entity_type' => 'order_stage',
            'items' => [
                [
                    'b24_id'         => $id,
                    'guid'           => $fields['ORIGIN_ID'],
                    'stage'          => $stageName,
                    'category'       => $directionName,
                ],
            ],
        ];

        try {
            $response = ExchangeService::send('SyncPacket', [$payload]);
            self::getLogger()->info('Стадия сделки успешно отправлена в 1С', [
                'payload'  => $payload,
                'response' => $response,
            ]);
        } catch (Throwable $exc) {
            self::getLogger()->exception($exc, 'Ошибка отправки стадии сделки (OrderStage)', [
                'deal_id' => $id,
                'payload' => $payload,
            ]);
        }

        return true;
    }

    private static function getLogger(): LoggerInterface
    {
        return self::$logger ??= LoggerFactory::get(static::class);
    }
}