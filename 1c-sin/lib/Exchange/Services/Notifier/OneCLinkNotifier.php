<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services\Notifier;

use Bitrix\Main\Loader;
use CCrmActivity;
use CCrmActivityType;
use CCrmActivityPriority;
use CCrmActivityStatus;
use CCrmActivityNotifyType;
use CCrmContentType;
use CCrmOwnerType;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerInterface;

class OneCLinkNotifier
{
    // Должен совпадать с классом, который перехватывает клики на фронте
    private const LINK_CSS_CLASS = 'rusgeocom-1c-link';
    private const SUBJECT = 'Ссылка на документ в 1С';

    private static LoggerInterface $logger;

    private static function logger(): LoggerInterface
    {
        return self::$logger ??= LoggerFactory::get(static::class);
    }

    /**
     * Создаёт "Дело" со ссылкой на 1С, либо обновляет уже существующее (не плодит дубли).
     *
     * @param int    $ownerTypeId CCrmOwnerType::Deal / Company / ...
     * @param int    $ownerId     ID сущности в Б24
     * @param string $url1c       Ссылка вида http://host/base#e1cib/data/...?ref=guid
     */
    public static function createOrUpdate(int $ownerTypeId, int $ownerId, string $url1c): void
    {
        if ($ownerId <= 0 || empty($url1c)) {
            return;
        }

        Loader::includeModule('crm');

        $existingId = self::findExisting($ownerTypeId, $ownerId);
        $description = self::buildDescription($url1c);

        if ($existingId) {
            $updateFields = [
                'DESCRIPTION' => $description,
                'DESCRIPTION_TYPE' => CCrmContentType::BBCode, // было Html
            ];
            CCrmActivity::Update($existingId, $updateFields, false, false);

            self::logger()->info('Обновлена ссылка на 1С в Деле', [
                'owner_type_id' => $ownerTypeId,
                'owner_id' => $ownerId,
                'activity_id' => $existingId,
            ]);
            return;
        }

        $addFields = [
            'OWNER_TYPE_ID' => $ownerTypeId,
            'OWNER_ID' => $ownerId,
            'TYPE_ID' => CCrmActivityType::Task,
            'SUBJECT' => self::SUBJECT,
            'DESCRIPTION' => $description,
            'DESCRIPTION_TYPE' => CCrmContentType::BBCode, // было Html
            'PRIORITY' => CCrmActivityPriority::Medium,
            'STATUS' => CCrmActivityStatus::Completed,
            'RESPONSIBLE_ID' => 1,
            'COMPLETED' => 'Y',
            'NOTIFY_TYPE' => CCrmActivityNotifyType::None,
            'BINDINGS' => [[
                'OWNER_TYPE_ID' => $ownerTypeId,
                'OWNER_ID' => $ownerId,
            ]],
        ];

        $activity = new CCrmActivity();
        $activityId = $activity->Add($addFields, false, false);

        if (!$activityId) {
            self::logger()->error('Не удалось создать Дело со ссылкой на 1С', [
                'owner_type_id' => $ownerTypeId,
                'owner_id' => $ownerId,
                'crm_error' => $activity->LAST_ERROR ?? '',
            ]);
            return;
        }

        self::logger()->info('Создано Дело со ссылкой на 1С', [
            'owner_type_id' => $ownerTypeId,
            'owner_id' => $ownerId,
            'activity_id' => $activityId,
        ]);
    }

    private static function findExisting(int $ownerTypeId, int $ownerId): ?int
    {
        $res = CCrmActivity::GetList(
            [],
            [
                'OWNER_TYPE_ID' => $ownerTypeId,
                'OWNER_ID' => $ownerId,
                'SUBJECT' => self::SUBJECT,
            ],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $row = $res->Fetch();
        return $row ? (int)$row['ID'] : null;
    }

    private static function buildDescription(string $url1c): string
    {
        $safeUrl = str_replace(']', '%5D', $url1c);

        return '[URL=' . $safeUrl . ']Открыть в 1С[/URL]';
    }
}