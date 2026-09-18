<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services\Builders;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\Service\Container;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;
use CCrmProductRow;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealDirectionEnum;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\ExchangeProtocol;
use Rusgeocom\Rusgeocom\Exchange\Services\SearchEntityService;

class OrderBuilder
{
    private const UF_NUMBER_1C        = 'UF_CRM_DEAL_3862607531962';
    private const UF_DIRECTION        = 'CATEGORY_ID';
    private const UF_MARK_DELETE      = 'UF_CRM_DEAL_3862607531946';
    private const UF_DATA_DOCUMENT_1C = 'UF_CRM_1780488739348';
    private const UF_NAME_AGREEMENT   = 'UF_CRM_1780491114583';

    private const PARENT_AGREEMENT       = 'UF_CRM_1726999670';
    private const PARENT_AGREEMENT_INDIV = 'UF_CRM_1780489768';
    private const PARENT_BUSINESS_REGION = 'UF_CRM_1724321615';
    private const PARENT_ORGANISATION    = 'UF_CRM_1776192243';
    private const PARENT_DELIVERY        = 'UF_CRM_1776192264';
    private const PARENT_WAREHOUSE       = 'UF_CRM_1749582650';

    private const IBLOCK_ID = 14;

    /**
     * Строит payload заказа для отправки в 1С по ID сделки Б24.
     * Ссылки на сущности оформлены как {b24_id, guid}.
     *
     * @param int $dealId
     * @return array|null null, если сделка не найдена
     */
    public static function build(int $dealId): ?array
    {
        Loader::includeModule('crm');
        Loader::includeModule('catalog');

        $factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
        if (!$factory) {
            return null;
        }

        $item = $factory->getItem($dealId);
        if (!$item) {
            return null;
        }

        $companyId   = (int)$item->get('COMPANY_ID') ?: null;
        $productRows = CCrmProductRow::LoadRows('D', $dealId) ?: [];

        $organisationId     = (int)$item->get(self::PARENT_ORGANISATION) ?: null;
        $warehouseId         = (int)$item->get(self::PARENT_WAREHOUSE) ?: null;
        $businessRegionId    = (int)$item->get(self::PARENT_BUSINESS_REGION) ?: null;
        $methodDeliveryId    = (int)$item->get(self::PARENT_DELIVERY) ?: null;
        $managerId           = (int)$item->get('ASSIGNED_BY_ID') ?: null;
        $contactId           = self::resolveContactId($dealId);
        [$agreementId, $agreementType] = self::resolveAgreement($item);
        $partnerId           = self::resolvePartnerId($companyId);

        return [
            'guid'             => (string)$item->get('ORIGIN_ID'),
            'markDelete'       => self::boolVal($item->get(self::UF_MARK_DELETE)),
            'dateDocument'     => self::formatDate($item->get(self::UF_DATA_DOCUMENT_1C)),
            'number1C'         => (string)$item->get(self::UF_NUMBER_1C),
            'company_id'       => self::makeRef($companyId, $companyId ? SearchEntityService::getCompanyGuid($companyId) : null),
            'partner_id'       => self::makeRef($partnerId, self::resolvePartnerGuid($partnerId)),
            'organisation'     => self::makeRef($organisationId, self::resolveSmartProcessGuid($organisationId, EntityType::SMART_PROCESS_ORGANISATION)),
            'agreement'        => self::makeRef($agreementId, self::resolveSmartProcessGuid($agreementId, $agreementType)),
            'currency'         => self::resolveCurrency((string)$item->get('CURRENCY_ID')),
            'summa'            => (float)$item->get('OPPORTUNITY'),
            'warehouseId'      => self::makeRef($warehouseId, self::resolveSmartProcessGuid($warehouseId, EntityType::SMART_PROCESS_WAREHOUSE)),
            'priceIncludesVat' => self::resolvePriceIncludesVat($productRows),
            'manager_id'       => self::makeRef($managerId, $managerId ? SearchEntityService::getUserGuid($managerId) : null),
            'comments'         => (string)$item->get('COMMENTS'),
            'agreementName'    => (string)$item->get(self::UF_NAME_AGREEMENT),
            'contacts_id'      => self::makeRef($contactId, $contactId ? SearchEntityService::getContactGuid($contactId) : null),
            'businessRegion'   => self::makeRef($businessRegionId, self::resolveSmartProcessGuid($businessRegionId, EntityType::SMART_PROCESS_BUSINESS_REGION)),
            'direction'        => self::resolveDirectionLabel((int)$item->get(self::UF_DIRECTION)),
            'methodDelivery'   => self::makeRef($methodDeliveryId, self::resolveSmartProcessGuid($methodDeliveryId, EntityType::SMART_PROCESS_METHOD_DELIVERY)),
            'products'         => self::buildProducts($dealId),
            'b24_id'           => $dealId,
        ];
    }

    /**
     * Универсальная обёртка {b24_id, guid} для ссылок на сущности.
     * Если id пустой — возвращаем null (сущность не привязана).
     */
    private static function makeRef(?int $id, ?string $guid): ?array
    {
        if (!$id) {
            return null;
        }

        return [
            'b24_id' => $id,
            'guid'   => $guid ?? '',
        ];
    }

    private static function resolveSmartProcessGuid(?int $id, ?EntityType $type): ?string
    {
        if (!$id || !$type) {
            return null;
        }

        return SearchEntityService::getSmartProcessGuid($id, $type);
    }

    /**
     * Товарные позиции читаем напрямую из CRM (реальные ProductRow сделки).
     * Поля skidka_ruch / skidka_auto / vid_price в модели Bitrix ProductRow
     * не хранятся (это исходные данные 1С), поэтому отдаём null.
     */
    private static function buildProducts(int $dealId): array
    {
        $rows = CCrmProductRow::LoadRows('D', $dealId) ?: [];

        $products = [];
        foreach ($rows as $row) {
            $productRowId     = (int)$row['ID'];
            $catalogProductId = (int)$row['PRODUCT_ID'];
            $taxRate          = (float)$row['TAX_RATE'];
            $quantity         = (float)$row['QUANTITY'];
            $price            = (float)$row['PRICE'];
            $summa            = $price * $quantity;

            $products[] = [
                'guid'            => self::resolveProductGuid($catalogProductId),
                'b24_id'          => $catalogProductId,
                'lineProductId'   => $productRowId,
                'lineProductId1c' => null,
                'count'           => self::formatNumber($quantity),
                'price'           => self::formatNumber($price),
                'summa'           => self::formatNumber($summa),
                'vat'             => self::formatVat($taxRate),
                'skidka_ruch'     => null,
                'skidka_auto'     => null,
                'vid_price'       => null,
            ];
        }

        return $products;
    }

    private static function resolveProductGuid(int $productId): string
    {
        if ($productId <= 0) {
            return '';
        }

        $row = ElementTable::getRow([
            'select' => ['XML_ID'],
            'filter' => ['ID' => $productId],
        ]);

        $xmlId = $row['XML_ID'] ?? '';
        if ($xmlId === '') {
            return '';
        }

        if (str_contains($xmlId, '#')) {
            [$parentGuid, $offerGuid] = explode('#', $xmlId, 2);
            $isEmptyOffer = $offerGuid === '' || $offerGuid === '00000000-0000-0000-0000-000000000000';

            return $isEmptyOffer ? $parentGuid : $offerGuid;
        }

        return $xmlId;
    }

    private static function formatVat(float $taxRate): string
    {
        $formatted = rtrim(rtrim(number_format($taxRate, 2, '.', ''), '0'), '.');

        return $formatted . '%';
    }

    private static function formatNumber($value): string
    {
        $float = (float)$value;
        $formatted = rtrim(rtrim(number_format($float, 4, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    /**
     * ID актуального реквизита компании (не GUID).
     */
    private static function resolvePartnerId(?int $companyId): ?int
    {
        if (!$companyId) {
            return null;
        }

        $requisite = (new EntityRequisite())->getList([
            'filter' => [
                '=ENTITY_TYPE_ID' => CCrmOwnerType::Company,
                '=ENTITY_ID'      => $companyId,
            ],
            'select' => ['ID'],
            'order'  => ['ID' => 'DESC'],
            'limit'  => 1,
        ])->fetch();

        return $requisite ? (int)$requisite['ID'] : null;
    }

    /**
     * GUID реквизита (XML_ID) — SearchEntityService не резолвит EntityRequisite,
     * поэтому получаем отдельным запросом.
     */
    private static function resolvePartnerGuid(?int $requisiteId): ?string
    {
        if (!$requisiteId) {
            return null;
        }

        $requisite = (new EntityRequisite())->getList([
            'filter' => ['=ID' => $requisiteId],
            'select' => ['XML_ID'],
            'limit'  => 1,
        ])->fetch();

        return $requisite['XML_ID'] ?? null;
    }

    /**
     * Возвращает [id, EntityType] соглашения — индивидуальное имеет приоритет.
     */
    private static function resolveAgreement($item): array
    {
        $indivId = (int)$item->get(self::PARENT_AGREEMENT_INDIV);
        if ($indivId > 0) {
            return [$indivId, EntityType::SMART_PROCESS_AGREEMENT_INDIV];
        }

        $typeId = (int)$item->get(self::PARENT_AGREEMENT);
        if ($typeId > 0) {
            return [$typeId, EntityType::SMART_PROCESS_AGREEMENT_TYPE];
        }

        return [null, null];
    }

    private static function resolveContactId(int $dealId): ?int
    {
        $row = DealContactTable::getList([
            'filter' => ['=DEAL_ID' => $dealId],
            'select' => ['CONTACT_ID'],
            'order'  => ['IS_PRIMARY' => 'DESC'],
            'limit'  => 1,
        ])->fetch();

        return $row ? (int)$row['CONTACT_ID'] : null;
    }

    private static function resolveDirectionLabel(int $categoryId): string
    {
        foreach (DealDirectionEnum::cases() as $case) {
            if ($case->getB24EnumId() === $categoryId) {
                return $case->value;
            }
        }

        return DealDirectionEnum::RETAIL->value;
    }

    private static function resolveCurrency(string $currencyCode): string
    {
        $numeric = array_flip(ExchangeProtocol::CURRENCY)[$currencyCode] ?? null;
        return $numeric !== null ? (string)$numeric : '643';
    }

    private static function resolvePriceIncludesVat(array $productRows): bool
    {
        if (empty($productRows)) {
            return true;
        }

        $firstRow = reset($productRows);

        return ($firstRow['TAX_INCLUDED'] ?? 'Y') === 'Y';
    }

    private static function formatDate(?DateTime $date): string
    {
        return $date ? $date->format('Y-m-d H:i:s') : '';
    }

    private static function boolVal(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, ['Y', true, 1, '1'], true);
    }
}