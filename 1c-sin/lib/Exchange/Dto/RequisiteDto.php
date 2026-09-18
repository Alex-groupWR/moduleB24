<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Dto;

use CCrmOwnerType;


final class RequisiteDto
{
    public const FIELD_MARK_DELETE   = 'UF_CRM_1774516144';
    public const FIELD_IS_WHOLESALER = 'UF_CRM_1776109744';

    // Индексы соответствуют PRESET_ID в Б24 (начинается с 1)
    public const PRESET_BY_ID = [
        1 => self::PRESET_ORGANIZATION,
        2 => self::PRESET_INDIVIDUAL,
        3 => self::PRESET_FIS_LICO,
        4 => self::PRESET_NON_RESIDENT,
    ];

    public const PRESET_ORGANIZATION = 'Организация';
    public const PRESET_INDIVIDUAL   = 'Индивидуальный предприниматель';
    public const PRESET_FIS_LICO     = 'Физическое лицо';
    public const PRESET_NON_RESIDENT = 'Юр. лицо (нерезидент)';

    public function __construct(
        public readonly string $guid,
        public readonly string $companyName,
        public readonly string $companyFullName,
        public readonly string $preset,
        public readonly bool   $markDelete,
        public readonly bool   $isWholesaler,
        public readonly string $inn,
        public readonly string $kpp,
        public readonly string $ogrn,
    ) {}

    /**
     * Строим DTO из запроса 1С (сырой массив контрагента).
     * Обратите внимание: KPP/OGRN читаются без префикса RQ_ — так их шлёт 1С.
     */
    public static function fromRequest(array $data): self
    {
        return new self(
            guid: (string)($data['guid'] ?? ''),
            companyName: (string)($data['companyName'] ?? ''),
            companyFullName: (string)($data['companyFullName'] ?? $data['companyName'] ?? ''),
            preset: (string)($data['preset'] ?? self::PRESET_ORGANIZATION),
            markDelete: self::boolVal($data['markDelete'] ?? false),
            isWholesaler: self::boolVal($data['isWholesaler'] ?? false),
            inn: (string)($data['INN'] ?? ''),
            kpp: (string)($data['KPP'] ?? ''),
            ogrn: (string)($data['OGRN'] ?? ''),
        );
    }

    /**
     * Строим DTO из строки EntityRequisite (для чтения / build()).
     */
    public static function fromCrmItem(array $requisiteRow): self
    {
        $presetId = (int)($requisiteRow['PRESET_ID'] ?? 0);

        return new self(
            guid: (string)($requisiteRow['XML_ID'] ?? ''),
            companyName: (string)($requisiteRow['RQ_COMPANY_NAME'] ?? ''),
            companyFullName: (string)($requisiteRow['RQ_COMPANY_FULL_NAME'] ?? ''),
            // Fallback на "Организация" — защитно; на практике сюда не попадаем
            // с неизвестным PRESET_ID, т.к. KontragentValidate::check() блокирует
            // build() раньше через collectAllErrors().
            preset: self::PRESET_BY_ID[$presetId] ?? self::PRESET_ORGANIZATION,
            markDelete: self::boolVal($requisiteRow[self::FIELD_MARK_DELETE] ?? false),
            isWholesaler: self::boolVal($requisiteRow[self::FIELD_IS_WHOLESALER] ?? false),
            inn: (string)($requisiteRow['RQ_INN'] ?? ''),
            kpp: (string)($requisiteRow['RQ_KPP'] ?? ''),
            ogrn: (string)($requisiteRow['RQ_OGRN'] ?? ''),
        );
    }

    /**
     * В массив полей для EntityRequisite->add()/update().
     */
    public function toCrmFields(?int $companyId): array
    {
        $fields = [
            'ENTITY_TYPE_ID'          => CCrmOwnerType::Company,
            'ENTITY_ID'               => $companyId ?? 0,
            'PRESET_ID'               => array_flip(self::PRESET_BY_ID)[$this->preset] ?? 1,
            'NAME'                    => $this->companyName,
            'XML_ID'                  => $this->guid,
            'RQ_COMPANY_NAME'         => $this->companyName,
            'RQ_COMPANY_FULL_NAME'    => $this->companyFullName,
            self::FIELD_MARK_DELETE   => $this->markDelete,
            self::FIELD_IS_WHOLESALER => $this->isWholesaler,
        ];

        if ($this->inn !== '') {
            $fields['RQ_INN'] = $this->inn;
        }
        if ($this->kpp !== '') {
            $fields['RQ_KPP'] = $this->kpp;
        }
        if ($this->ogrn !== '') {
            $fields['RQ_OGRN'] = $this->ogrn;
        }

        return $fields;
    }

    /**
     * Для ответа обратно в 1С (верхний уровень KontragentBuilder::build()).
     * Ключи 'RQ_KPP'/'RQ_OGRN' сохранены как в исходном коде — см. докблок класса.
     */
    public function toResponseArray(int $b24Id): array
    {
        return [
            'guid'            => $this->guid,
            'b24_id'          => $b24Id,
            'companyName'     => $this->companyName,
            'companyFullName' => $this->companyFullName,
            'preset'          => $this->preset,
            'markDelete'      => $this->markDelete,
            'isWholesaler'    => $this->isWholesaler,
            'INN'             => $this->inn,
            'RQ_KPP'          => $this->kpp,
            'RQ_OGRN'         => $this->ogrn,
        ];
    }

    private static function boolVal(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array($value, ['Y', true, 1, '1'], true);
    }
}
