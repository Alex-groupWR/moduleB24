<?php

namespace Rusgeocom\Rusgeocom\Exchange\Validate;

use Bitrix\Main\Loader;
use CCrmOwnerType;
use CCrmActivity;
use CCrmActivityType;
use CCrmActivityPriority;
use CCrmActivityStatus;
use CCrmContentType;
use CCrmActivityNotifyType;
use Rusgeocom\Rusgeocom\Exchange\Enum\SegmentEnum;
use Rusgeocom\Rusgeocom\Exchange\Services\Builders\KontragentBuilder;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class KontragentValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'companyName', 'markDelete'];
    private const PRESET_FIS_LICO = 'Физическое лицо';
    private const PRESET_INDIVIDUAL = 'Индивидуальный предприниматель';
    private const PRESET_BY_ID = [
        1 => 'Организация',
        2 => 'Индивидуальный предприниматель',
        3 => 'Физическое лицо',
        4 => 'Юр. лицо (нерезидент)',
    ];

    private const REQUIRED_COMPANY_KEYS = ['guid', 'companyName', 'markDelete', 'segment', 'statusWork', 'manage_id'];

    public static function checkParams(array $data): array
    {
        $error = parent::validate($data, self::REQUIRED_KEYS);
        if (!empty($error)) {
            return $error;
        }

        if (!empty($data['company'])) {
            $error = parent::validate($data['company'], self::REQUIRED_COMPANY_KEYS);
            if (!empty($error)) {
                return $error;
            }
        }

        return [];
    }

    public static function check(array $companyFields, array $requisiteData): bool
    {
        $errors = self::collectAllErrors($companyFields, $requisiteData);

        if (empty($errors)) {
            return true;
        }

        self::notifyTimeline($companyFields, $errors);
        return false;
    }

    public static function collectAllErrors(array $companyFields, array $requisiteData): array
    {
        $errors = [];

        $segmentId = (int)($companyFields[KontragentBuilder::SEGMENT_FIELD] ?? 0);
        if ($segmentId === 0) {
            $errors[] = '[Компания] Не заполнен сегмент рынка';
        } elseif (SegmentEnum::getTextById($segmentId) === null) {
            $errors[] = '[Компания] Неизвестное значение сегмента рынка (ID: ' . $segmentId . '). '
                . 'Допустимые значения: '
                . implode(', ', array_map(fn($c) => $c->label(), SegmentEnum::cases()));
        }
        if (empty($requisiteData)) {
            $errors[] = '[Реквизит] Реквизит не создан — добавьте реквизит в карточке компании';
        } else {
            $presetId = (int)($requisiteData['PRESET_ID'] ?? 0);
            $presetName = self::PRESET_BY_ID[$presetId] ?? 'Организация';

            if ($presetName === self::PRESET_FIS_LICO) {
                // Физлицо
                if (empty($requisiteData['RQ_LAST_NAME']) || empty($requisiteData['RQ_FIRST_NAME']) || empty($requisiteData['RQ_SECOND_NAME'])) {
                    $errors[] = '[Реквизит] Проверьте ФИО';
                }
            }else if ($presetName === self::PRESET_INDIVIDUAL) {
                // ИП
                if (empty($requisiteData['RQ_COMPANY_NAME'])) {
                    $errors[] = '[Реквизит] Не заполнено наименование организации';
                }
                if (empty($requisiteData['RQ_INN'])) {
                    $errors[] = '[Реквизит] Не заполнен ИНН';
                }
            } else {
                // Юрлицо / нерезидент
                if (empty($requisiteData['RQ_COMPANY_NAME'])) {
                    $errors[] = '[Реквизит] Не заполнено наименование организации';
                }
                if (empty($requisiteData['RQ_INN'])) {
                    $errors[] = '[Реквизит] Не заполнен ИНН';
                }
                if (empty($requisiteData['RQ_KPP'])) {
                    $errors[] = '[Реквизит] Не заполнен КПП';
                }
            }
        }

        if (!empty($requisiteData)) {
            $presetId = (int)($requisiteData['PRESET_ID'] ?? 0);
            $presetName = self::PRESET_BY_ID[$presetId] ?? 'Организация';

            if ($presetName === self::PRESET_FIS_LICO) {
                $businessRegionId = (int)($companyFields[KontragentBuilder::BUSINESS_REGION_FIELD] ?? 0);
                if ($businessRegionId === 0) {
                    $errors[] = '[Компания] Для физлица требуется бизнес-регион';
                }
            }
        }

        return $errors;
    }

    public static function notifyTimeline(array $companyFields, array $errors): void
    {
        Loader::includeModule('crm');

        $logger = LoggerFactory::get(static::class);
        $companyId = (int)($companyFields['ID'] ?? 0);

        if ($companyId <= 0) {
            $logger->warning('Таймлайн-уведомление не создано: ID компании не определён', [
                'errors' => $errors,
            ]);
            return;
        }

        $existingId = self::findOpenNotification($companyId);

        if ($existingId) {
            $updateFields = [
                'DESCRIPTION' => self::buildText($companyFields, $errors),
                'DESCRIPTION_TYPE' => CCrmContentType::Html,
            ];

            CCrmActivity::Update($existingId, $updateFields, false, false);

            $logger->info('Таймлайн-задача обновлена актуальным списком ошибок', [
                'company_id' => $companyId,
                'activity_id' => $existingId,
                'error_count' => count($errors),
            ]);
            return;
        }

        $responsibleId = (int)($companyFields['ASSIGNED_BY_ID'] ?? 1) ?: 1;

        $addFields = [
            'OWNER_TYPE_ID' => CCrmOwnerType::Company,
            'OWNER_ID' => $companyId,
            'TYPE_ID' => CCrmActivityType::Task,
            'SUBJECT' => self::subject(),
            'DESCRIPTION' => self::buildText($companyFields, $errors),
            'DESCRIPTION_TYPE' => CCrmContentType::Html,
            'PRIORITY' => CCrmActivityPriority::High,
            'STATUS' => CCrmActivityStatus::Waiting,
            'RESPONSIBLE_ID' => $responsibleId,
            'COMPLETED' => 'N',
            'NOTIFY_TYPE' => CCrmActivityNotifyType::None,
            'BINDINGS' => [[
                'OWNER_TYPE_ID' => CCrmOwnerType::Company,
                'OWNER_ID' => $companyId,
            ]],
        ];

        $activity = new CCrmActivity();
        $activityId = $activity->Add($addFields, false, false);

        if (!$activityId) {
            $logger->error('Не удалось создать таймлайн-задачу для контрагента', [
                'company_id' => $companyId,
                'crm_error' => $activity->LAST_ERROR ?? '',
                'errors' => $errors,
            ]);
            return;
        }

        $logger->info('Создана таймлайн-задача: требуется заполнить данные контрагента', [
            'company_id' => $companyId,
            'activity_id' => $activityId,
            'error_count' => count($errors),
        ]);
    }

    private static function findOpenNotification(int $companyId): ?int
    {
        $res = CCrmActivity::GetList(
            [],
            [
                'OWNER_TYPE_ID' => CCrmOwnerType::Company,
                'OWNER_ID' => $companyId,
                'SUBJECT' => self::subject(),
                'COMPLETED' => 'N',
            ],
            false,
            ['nTopCount' => 1],
            ['ID']
        );

        $row = $res->Fetch();
        return $row ? (int)$row['ID'] : null;
    }

    private static function subject(): string
    {
        return '⚠ Заполните данные контрагента для синхронизации с 1С';
    }

    private static function buildText(array $companyFields, array $errors): string
    {
        $items = implode(', ', $errors);
        return "Что требуется заполнить:{$items}";
    }
}