<?php

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Rusgeocom\Rusgeocom\Exchange\Services\Base\BaseSmartProcessService;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\ExchangeProtocol;

class AgreementService extends BaseSmartProcessService
{
    private const TYPE_INDIVIDUAL = 1068;
    private const TYPE_TYPICAL = 1064;

    private int $currentTypeId = self::TYPE_TYPICAL;

    public function setType(int $typeId): self
    {
        $this->currentTypeId = $typeId;
        return $this;
    }

    protected function getEntityTypeId(): int
    {
        return $this->currentTypeId;
    }

    protected function getGuidFieldName(): string
    {
        return 'XML_ID';
    }

    protected function getFieldMap(): array
    {
        if ($this->currentTypeId === self::TYPE_TYPICAL) {
            return ['markDelete' => 'UF_CRM_12_1773347305712'];
        }

        return [
            'companyId' => 'UF_CRM_13_1726998237',
            'organisationId' => 'UF_CRM_13_ORGANISATION',
            'operation' => 'UF_CRM_13_1726998361',
            'currency' => 'UF_CRM_13_1726998483',
            'markDelete' => 'UF_CRM_13_1773315144139',
        ];
    }

    protected function getBindingMap(): array
    {
        return [
            'companyId' => EntityType::COMPANY,
            'organisationId' => EntityType::SMART_PROCESS_1118,
        ];
    }

    protected function getEnumFields(): array
    {
        return ['operation' => true];
    }

    protected function prepareCustomFields(array $request, array $fields): array
    {
        if (isset($request['currency']) && $this->currentTypeId === self::TYPE_INDIVIDUAL) {
            $fields['UF_CRM_13_1726998483'] = ExchangeProtocol::CURRENCY[$request['currency']] ?? 'RUB';
        }
        return $fields;
    }

    public function determineType(array $request): int
    {
        $companyId = $request['companyId']['b24_id'] > 0
            ? $request['companyId']['b24_id']
            : $request['companyId']['guid'];

        return !empty($companyId) && $companyId !== '00000000-0000-0000-0000-000000000000'
            ? self::TYPE_INDIVIDUAL
            : self::TYPE_TYPICAL;
    }

    protected function mapCustomFields(\Bitrix\Crm\Item $item, array $result): array
    {
        if ($this->currentTypeId !== self::TYPE_INDIVIDUAL) {
            return $result;
        }

        // Обратная конвертация валюты: значение B24 → ключ из ExchangeProtocol::CURRENCY
        $b24Val = $item->get('UF_CRM_13_1726998483');
        if ($b24Val !== null) {
            $result['currency'] = array_flip(ExchangeProtocol::CURRENCY)[$b24Val] ?? $b24Val;
        }

        return $result;
    }
}