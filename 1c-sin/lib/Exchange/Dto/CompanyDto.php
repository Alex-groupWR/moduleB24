<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Dto;

use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\Enum\SegmentEnum;
use Rusgeocom\Rusgeocom\Exchange\Services\SearchEntityService;

final class CompanyDto
{
    public const FIELD_SEGMENT         = 'UF_CRM_1774519329551';
    public const FIELD_STATUS_WORK     = 'UF_CRM_1774519961417';
    public const FIELD_MARK_DELETE     = 'UF_CRM_COMPANY_3885072309690';
    public const FIELD_IS_BUYER        = 'UF_CRM_1776097823371';
    public const FIELD_IS_SUPPLIER     = 'UF_CRM_1776097831202';
    public const FIELD_IS_COMPETITOR   = 'UF_CRM_1776097840683';
    public const FIELD_IS_OTHER        = 'UF_CRM_1776097854105';
    public const FIELD_BUSINESS_REGION = 'PARENT_ID_1032';

    public const DEFAULT_SEGMENT_ID = 5284;

    /**
     * @param array<string, bool> $providedFields Список ключей, фактически переданных в запросе
     */
    public function __construct(
        public readonly string  $guid,
        public readonly ?string $companyName,
        public readonly ?int    $managerId,
        public readonly ?string $managerGuid,
        public readonly ?string $segment,
        public readonly ?bool   $statusWork,
        public readonly ?bool   $markDelete,
        public readonly ?bool   $isBuyer,
        public readonly ?bool   $isSupplier,
        public readonly ?bool   $isCompetitor,
        public readonly ?bool   $isOther,
        public readonly ?int    $businessRegionId,
        public readonly ?string $businessRegionGuid,
        private readonly array  $providedFields = [],
    ) {}

    public static function fromRequest(array $data, bool $applyDefaultSegment = false): self
    {
        $provided = [];
        foreach ($data as $key => $_) {
            $provided[$key] = true;
        }

        // Менеджер
        $managerId = null;
        $managerGuid = null;
        if (array_key_exists('manage_id', $data)) {
            if (is_array($data['manage_id'])) {
                $managerGuid = $data['manage_id']['guid'] ?? null;
                $managerId = !empty($data['manage_id']['b24_id'])
                    ? (int)$data['manage_id']['b24_id']
                    : (isset($managerGuid)
                        ? (SearchEntityService::searchUser($managerGuid) ?? 1)
                        : 1);
            } elseif ($data['manage_id'] === null) {
                $managerId = 1; // У компании в B24 обычно обязателен ответственный, дефолт 1
            }
        }

        // Регион
        $businessRegionId = null;
        $businessRegionGuid = null;
        if (array_key_exists('businessRegion_id', $data)) {
            if (is_array($data['businessRegion_id'])) {
                $businessRegionGuid = $data['businessRegion_id']['guid'] ?? null;
                $businessRegionId = !empty($data['businessRegion_id']['b24_id'])
                    ? (int)$data['businessRegion_id']['b24_id']
                    : (isset($businessRegionGuid)
                        ? SearchEntityService::searchSmartProcess($businessRegionGuid, EntityType::SMART_PROCESS_BUSINESS_REGION)
                        : null);
            }
        }

        // Сегмент
        $segment = null;
        if (array_key_exists('segment', $data)) {
            $segment = $data['segment'];
            if (empty($segment) && $applyDefaultSegment) {
                $segment = SegmentEnum::getTextById(self::DEFAULT_SEGMENT_ID);
            }
        }

        return new self(
            guid: (string)($data['guid'] ?? ''),
            companyName: array_key_exists('companyName', $data) ? ($data['companyName'] !== null ? (string)$data['companyName'] : null) : null,
            managerId: $managerId,
            managerGuid: $managerGuid,
            segment: $segment,
            statusWork: array_key_exists('statusWork', $data) ? ($data['statusWork'] !== null ? self::boolVal($data['statusWork']) : null) : null,
            markDelete: array_key_exists('markDelete', $data) ? ($data['markDelete'] !== null ? self::boolVal($data['markDelete']) : null) : null,
            isBuyer: array_key_exists('isBuyer', $data) ? ($data['isBuyer'] !== null ? self::boolVal($data['isBuyer']) : null) : null,
            isSupplier: array_key_exists('isSupplier', $data) ? ($data['isSupplier'] !== null ? self::boolVal($data['isSupplier']) : null) : null,
            isCompetitor: array_key_exists('isCompetitor', $data) ? ($data['isCompetitor'] !== null ? self::boolVal($data['isCompetitor']) : null) : null,
            isOther: array_key_exists('isOther', $data) ? ($data['isOther'] !== null ? self::boolVal($data['isOther']) : null) : null,
            businessRegionId: $businessRegionId,
            businessRegionGuid: $businessRegionGuid,
            providedFields: $provided,
        );
    }

    public static function fromCrmItem(array $fields, ?string $managerGuid = null, ?string $businessRegionGuid = null): self
    {
        $businessRegionId = !empty($fields[self::FIELD_BUSINESS_REGION])
            ? (int)$fields[self::FIELD_BUSINESS_REGION]
            : null;

        return new self(
            guid: (string)($fields['ORIGIN_ID'] ?? ''),
            companyName: (string)($fields['TITLE'] ?? ''),
            managerId: (int)($fields['ASSIGNED_BY_ID'] ?? 1),
            managerGuid: $managerGuid,
            segment: SegmentEnum::getTextById((int)($fields[self::FIELD_SEGMENT] ?? 0)),
            statusWork: self::boolVal($fields[self::FIELD_STATUS_WORK] ?? false),
            markDelete: self::boolVal($fields[self::FIELD_MARK_DELETE] ?? false),
            isBuyer: self::boolVal($fields[self::FIELD_IS_BUYER] ?? false),
            isSupplier: self::boolVal($fields[self::FIELD_IS_SUPPLIER] ?? false),
            isCompetitor: self::boolVal($fields[self::FIELD_IS_COMPETITOR] ?? false),
            isOther: self::boolVal($fields[self::FIELD_IS_OTHER] ?? false),
            businessRegionId: $businessRegionId,
            businessRegionGuid: $businessRegionGuid,
            providedFields: [],
        );
    }

    /**
     * Формирует массив для CCrmItem->set() / CompanyTable::update().
     * Включает только те ключи, которые явно были переданы в запросе.
     * Если пришел null — передает пустоту ('') для очистки поля в Битрикс24.
     */
    public function toCrmFields(): array
    {
        $fields = [
            'ORIGIN_ID' => $this->guid,
        ];

        if ($this->has('companyName')) {
            $fields['TITLE'] = $this->companyName ?? '';
        }

        if ($this->has('manage_id') && $this->managerId !== null) {
            $fields['ASSIGNED_BY_ID'] = $this->managerId;
        }

        if ($this->has('segment')) {
            // Для списка UF_CRM_* передача '' очищает значение в CRM
            $fields[self::FIELD_SEGMENT] = $this->segment !== null
                ? SegmentEnum::getIdByText($this->segment)
                : '';
        }

        if ($this->has('businessRegion_id')) {
            // Очистка привязки к смарт-процессу
            $fields[self::FIELD_BUSINESS_REGION] = $this->businessRegionId ?? '';
        }

        $boolMap = [
            'statusWork'   => self::FIELD_STATUS_WORK,
            'markDelete'   => self::FIELD_MARK_DELETE,
            'isBuyer'      => self::FIELD_IS_BUYER,
            'isSupplier'   => self::FIELD_IS_SUPPLIER,
            'isCompetitor' => self::FIELD_IS_COMPETITOR,
            'isOther'      => self::FIELD_IS_OTHER,
        ];

        foreach ($boolMap as $requestKey => $crmField) {
            if ($this->has($requestKey)) {
                // Для boolean в CRM: если пришел null — передаем 'N' или false
                $val = $this->{$requestKey};
                $fields[$crmField] = $val !== null ? $val : false;
            }
        }

        return $fields;
    }

    public function has(string $field): bool
    {
        return !empty($this->providedFields[$field]);
    }

    public function toResponseArray(int $b24Id): array
    {
        $result = [
            'b24_id'       => $b24Id,
            'guid'         => $this->guid,
            'companyName'  => $this->companyName ?? '',
            'manage_id'    => [
                'b24_id' => $this->managerId ?? 1,
                'guid'   => $this->managerGuid,
            ],
            'segment'      => $this->segment,
            'statusWork'   => (bool)$this->statusWork,
            'markDelete'   => (bool)$this->markDelete,
            'isBuyer'      => (bool)$this->isBuyer,
            'isSupplier'   => (bool)$this->isSupplier,
            'isCompetitor' => (bool)$this->isCompetitor,
            'isOther'      => (bool)$this->isOther,
        ];

        if ($this->businessRegionId !== null && $this->businessRegionId > 0) {
            $result['businessRegion_id'] = [
                'b24_id' => $this->businessRegionId,
                'guid'   => $this->businessRegionGuid,
            ];
        }

        return $result;
    }

    private static function boolVal(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, ['Y', true, 1, '1'], true);
    }
}