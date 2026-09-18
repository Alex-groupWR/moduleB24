<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services\Notifier;

use Bitrix\Main\Loader;
use CCrmOwnerType;
use CCrmActivity;
use CCrmActivityType;
use CCrmActivityPriority;
use CCrmActivityStatus;
use CCrmContentType;
use CCrmActivityNotifyType;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

/**
 * Создаёт/обновляет Дело в таймлайне сделки при ошибках синхронизации с 1С.
 * По аналогии с KontragentValidate::notifyTimeline(), но обобщено под CCrmOwnerType::Deal.
 */
class DealActivityNotifier
{
    private const SUBJECT = '⚠ Ошибка синхронизации заказа с 1С';

    public static function notifyError(int $dealId, string $message, int $responsibleId = 1): void
    {
        if ($dealId <= 0) {
            return;
        }

        Loader::includeModule('crm');

        $logger = LoggerFactory::get(static::class);

        $existingId = self::findOpenActivity($dealId);

        if ($existingId) {
            CCrmActivity::Update($existingId, [
                'DESCRIPTION' => self::buildText($message),
                'DESCRIPTION_TYPE' => CCrmContentType::Html,
            ], false, false);

            $logger->info('Дело по ошибке синхронизации обновлено', [
                'deal_id' => $dealId,
                'activity_id' => $existingId,
            ]);
            return;
        }

        $addFields = [
            'OWNER_TYPE_ID' => CCrmOwnerType::Deal,
            'OWNER_ID' => $dealId,
            'TYPE_ID' => CCrmActivityType::Task,
            'SUBJECT' => self::SUBJECT,
            'DESCRIPTION' => self::buildText($message),
            'DESCRIPTION_TYPE' => CCrmContentType::Html,
            'PRIORITY' => CCrmActivityPriority::High,
            'STATUS' => CCrmActivityStatus::Waiting,
            'RESPONSIBLE_ID' => $responsibleId ?: 1,
            'COMPLETED' => 'N',
            'NOTIFY_TYPE' => CCrmActivityNotifyType::None,
            'BINDINGS' => [[
                'OWNER_TYPE_ID' => CCrmOwnerType::Deal,
                'OWNER_ID' => $dealId,
            ]],
        ];

        $activity = new CCrmActivity();
        $activityId = $activity->Add($addFields, false, false);

        if (!$activityId) {
            $logger->error('Не удалось создать Дело по ошибке синхронизации заказа', [
                'deal_id' => $dealId,
                'crm_error' => $activity->LAST_ERROR ?? '',
                'message' => $message,
            ]);
            return;
        }

        $logger->info('Создано Дело: ошибка синхронизации заказа с 1С', [
            'deal_id' => $dealId,
            'activity_id' => $activityId,
        ]);
    }

    public static function resolveOpenActivity(int $dealId): void
    {
        if ($dealId <= 0) {
            return;
        }

        Loader::includeModule('crm');

        $existingId = self::findOpenActivity($dealId);
        if (!$existingId) {
            return;
        }

        CCrmActivity::Update($existingId, ['COMPLETED' => 'Y'], false, false);
    }

    private static function findOpenActivity(int $dealId): ?int
    {
        $res = CCrmActivity::GetList(
            [],
            [
                'OWNER_TYPE_ID' => CCrmOwnerType::Deal,
                'OWNER_ID' => $dealId,
                'SUBJECT' => self::SUBJECT,
                'COMPLETED' => 'N',
            ],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $row = $res->Fetch();
        return $row ? (int)$row['ID'] : null;
    }

    private static function buildText(string $message): string
    {
        return 'Что произошло: ' . htmlspecialchars($message, ENT_QUOTES);
    }
}