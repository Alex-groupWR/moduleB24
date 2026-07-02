<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Crm\Binding\DealContactTable;
use Bitrix\Crm\Discount;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\ProductRow;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;
use CCrmProductRow;
use CIBlockElement;
use Rusgeocom\Rusgeocom\Exchange\Dto\ProductRowDto;
use Rusgeocom\Rusgeocom\Exchange\Dto\ResolvedRelationsDto;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealDirectionEnum;
use Rusgeocom\Rusgeocom\Exchange\Enum\EntityType;
use Rusgeocom\Rusgeocom\Exchange\Tables\RusgeocomOrderProductTable;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;
use Rusgeocom\Rusgeocom\Tools\Log\AbstractLogger;
use Rusgeocom\Rusgeocom\Tools\Log\LoggerFactory;

class OrderService
{
    use ExchangeHelperTrait;

    // ------------------------------------------------------------------
    // Поля сделки (UF)
    // ------------------------------------------------------------------
    private const UF_NUMBER_1C         = 'UF_CRM_DEAL_3862607531962';
    private const UF_DIRECTION         = 'CATEGORY_ID';
    private const UF_STAGE             = 'STAGE_ID';
    private const UF_MARK_DELETE       = 'UF_CRM_DEAL_3862607531946';
    private const UF_DATA_DOCUMENT_1C  = 'UF_CRM_1780488739348';
    private const UF_NAME_AGREEMENT    = 'UF_CRM_1780491114583';

    // ------------------------------------------------------------------
    // Привязки к смарт-процессам
    // ------------------------------------------------------------------
    private const PARENT_AGREEMENT        = 'UF_CRM_1726999670';
    private const PARENT_AGREEMENT_INDIV  = 'UF_CRM_1780489768';
    private const PARENT_BUSINESS_REGION  = 'UF_CRM_1724321615';
    private const PARENT_ORGANISATION     = 'UF_CRM_1776192243';
    private const PARENT_DELIVERY         = 'UF_CRM_1776192264';
    private const PARENT_WAREHOUSE        = 'UF_CRM_1749582650';

    private const IBLOCK_ID               = 14;
    private const DEFAULT_MANAGER_ID      = 1;
    private const SORT_STEP               = 10;

    private AbstractLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerFactory::get(static::class);
    }

    // ------------------------------------------------------------------
    // Точка входа
    // ------------------------------------------------------------------

    public function sync(array $request): array
    {
        Loader::includeModule('crm');
        Loader::includeModule('catalog');

        $guid     = (string)($request['guid'] ?? '');
        $resolved = $this->resolveRelations($request);
        $dealId   = $this->findDealId($request);
        $isNew    = $dealId === null;
        $factory  = $this->getFactory();

        $item = $isNew ? $factory->createItem() : $factory->getItem($dealId);

        if (!$isNew && !$item) {
            return $this->errorResult('DEAL_ERROR', "Сделка {$dealId} не найдена", $guid);
        }

        foreach ($this->buildFields($request, $resolved) as $field => $value) {
            if ($item->hasField($field)) {
                $item->set($field, $value);
            }
        }

        $operation = $isNew
            ? $factory->getAddOperation($item)
            : $factory->getUpdateOperation($item);

        $operation->disableCheckAccess()
            ->disableAllChecks() // Отключает проверки валидации полей на стадиях
            ->disableAutomation(); // Отключает роботов складского учета и триггеры

        $result = $operation->disableCheckAccess()->launch();

        if (!$result->isSuccess()) {
            return $this->errorResult(
                'DEAL_SAVE_ERROR',
                implode(', ', $result->getErrorMessages()),
                $guid
            );
        }

        $savedId = (int)$item->getId();

        if (!empty($request['contacts_id'])) {
            $this->syncContact($savedId, $resolved->contactId);
        }

        $productRows = [];
        if (!empty($request['products'])) {
            $productRows = $this->syncProducts(
                $savedId,
                $request['products'],
                $resolved->warehouseId,
                (bool)($request['priceIncludesVat'] ?? true)
            );

            $this->persistProductRows($savedId, $request['products'], $productRows);
        }

        return $this->buildResponse($savedId, $guid, $isNew, $resolved, $productRows);
    }

    // ------------------------------------------------------------------
    // Резолв связанных сущностей
    // ------------------------------------------------------------------

    private function resolveRelations(array $request): ResolvedRelationsDto
    {
        $companyId   = $this->resolveCompanyId($request);
        $managerId   = $this->resolveManagerId((string)($request['manager_id'] ?? ''));
        $contactId   = ContactService::getExistId((string)($request['contacts_id'] ?? '')) ?: null;
        $warehouseId = (int)(WarehouseService::checkExistWarehouse((string)($request['warehouseId'] ?? ''))['b24_id'] ?? 0);

        $organisationId   = SearchEntityService::searchSmartProcess($request['organisation'],   EntityType::SMART_PROCESS_ORGANISATION);
        $businessRegionId = SearchEntityService::searchSmartProcess($request['businessRegion'], EntityType::SMART_PROCESS_BUSINESS_REGION);
        $methodDeliveryId = SearchEntityService::searchSmartProcess($request['methodDelivery'], EntityType::SMART_PROCESS_METHOD_DELIVERY);
        $typeAgreementId  = SearchEntityService::searchSmartProcess($request['agreement'],      EntityType::SMART_PROCESS_AGREEMENT_INDIV);
        $indivAgreementId = SearchEntityService::searchSmartProcess($request['agreement'],      EntityType::SMART_PROCESS_AGREEMENT_TYPE);
        $warehouseSPId    = SearchEntityService::searchSmartProcess($request['warehouseId'],    EntityType::SMART_PROCESS_WAREHOUSE);

        return new ResolvedRelationsDto(
            companyId:        $companyId,
            managerId:        $managerId,
            contactId:        $contactId,
            warehouseId:      $warehouseId,
            warehouseSPId:    $warehouseSPId,
            organisationId:   $organisationId,
            businessRegionId: $businessRegionId,
            methodDeliveryId: $methodDeliveryId,
            agreementId:      $typeAgreementId ?? $indivAgreementId,
            typeAgreementId:  $typeAgreementId,
            indivAgreementId: $indivAgreementId,
        );
    }

    private function buildFields(array $data, ResolvedRelationsDto $resolved): array
    {
        $fields = [
            'ORIGIN_ID'             => $data['guid']          ?? '',
            'ASSIGNED_BY_ID'        => $resolved->managerId,
            'COMMENTS'              => $data['comments']       ?? '',
            'OPPORTUNITY'           => (float)($data['summa'] ?? 0),
            'IS_MANUAL_OPPORTUNITY' => 'Y',
            self::UF_NUMBER_1C      => $data['number1C']      ?? '',
            self::UF_MARK_DELETE    => $data['markDelete']    ?? false,
            self::UF_NAME_AGREEMENT => $data['agreementName'] ?? '',
            self::PARENT_ORGANISATION    => $resolved->organisationId,
            self::PARENT_BUSINESS_REGION => $resolved->businessRegionId,
            self::PARENT_DELIVERY        => $resolved->methodDeliveryId,
            self::PARENT_AGREEMENT       => $resolved->typeAgreementId,
            self::PARENT_AGREEMENT_INDIV => $resolved->indivAgreementId,
            self::PARENT_WAREHOUSE       => $resolved->warehouseSPId,
        ];

        if ($resolved->companyId !== null) {
            $fields['COMPANY_ID'] = $resolved->companyId;
        }


        $fields = $this->applyDateDocument($fields, $data['dateDocument'] ?? null);
        $fields = $this->applyDirectionAndStage($fields, $data['direction'] ?? null, $data['stage'] ?? null);

        return $fields;
    }

    private function applyDateDocument(array $fields, ?string $dateDocument): array
    {
        if (empty($dateDocument)) {
            return $fields;
        }

        $ts = strtotime($dateDocument);
        if ($ts === false) {
            $this->logger->warning('Не удалось распознать dateDocument', ['value' => $dateDocument]);
            return $fields;
        }

        $fields[self::UF_DATA_DOCUMENT_1C] = new DateTime(date('d.m.Y H:i:s', $ts), 'd.m.Y H:i:s');

        return $fields;
    }

    private function applyDirectionAndStage(array $fields, ?string $direction, ?string $stage): array
    {
        if (empty($direction)) {
            return $fields;
        }

        $enum = DealDirectionEnum::tryFrom($direction);
        if ($enum) {
            $fields[self::UF_DIRECTION] = $enum->getB24EnumId();

            if (!empty($stage)) {
                $stageEnum = $enum->getStageFromLabel($stage);

                if ($stageEnum) {
                    $fields[self::UF_STAGE] = $stageEnum->value;
                }
            }
        }

        return $fields;
    }

    // ------------------------------------------------------------------
    // Поиск существующей сделки
    // ------------------------------------------------------------------

    private function findDealId(array $data): ?int
    {
        if (!empty($data['b24_id']) && (int)$data['b24_id'] > 0) {
            $row = DealTable::getList([
                'filter' => ['=ID' => (int)$data['b24_id']],
                'select' => ['ID'],
            ])->fetch();

            if ($row) {
                return (int)$row['ID'];
            }
        }

        if (!empty($data['guid'])) {
            $row = DealTable::getList([
                'filter' => ['=ORIGIN_ID' => $data['guid']],
                'select' => ['ID'],
            ])->fetch();

            if ($row) {
                return (int)$row['ID'];
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Компания и менеджер
    // ------------------------------------------------------------------

    private function resolveCompanyId(array $data): ?int
    {

        return !empty($data['company_id'])
            ? SearchEntityService::searchCompany($data['company_id'])
            : null;
    }

    private function resolveManagerId(string $guid): int
    {
        return empty($guid)
            ? self::DEFAULT_MANAGER_ID
            : (SearchEntityService::searchUser($guid) ?? self::DEFAULT_MANAGER_ID);
    }

    // ------------------------------------------------------------------
    // Контакт
    // ------------------------------------------------------------------

    private function syncContact(int $dealId, ?int $contactId): void
    {
        if (!$contactId) {
            return;
        }

        $exists = DealContactTable::getList([
            'filter' => ['=DEAL_ID' => $dealId, '=CONTACT_ID' => $contactId],
            'select' => ['CONTACT_ID'],
        ])->fetch();

        if (!$exists) {
            DealContactTable::bindContacts($dealId, [
                ['CONTACT_ID' => $contactId, 'IS_PRIMARY' => 'Y', 'SORT' => 10],
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Товарные позиции — основная синхронизация
    // ------------------------------------------------------------------

    /**
     * Синхронизирует товарные строки сделки с данными из 1С.
     *
     * lineProductId == 0 → создать новую строку
     * lineProductId  > 0 → найти строку по ID и обновить
     * Строки чьи ID не пришли → удалить (не включаем в finalRows)
     *
     * @return array<int, array{b24_id: int, lineProductId: string, lineProductId1c: string}>
     */
    private function syncProducts(int $dealId, array $products, int $storeId, bool $taxInclude): array
    {
        $factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
        if (!$factory) {
            $this->logger->error('Фабрика сделок недоступна при синхронизации товаров', ['dealId' => $dealId]);
            return [];
        }

        $item = $factory->getItem($dealId);
        if (!$item) {
            $this->logger->error('Сделка не найдена при синхронизации товаров', ['dealId' => $dealId]);
            return [];
        }

        // Индексируем текущие строки сделки по ID для O(1) доступа
        $existingById = [];
        foreach ($item->getProductRows() as $row) {
            $existingById[(int)$row->getId()] = $row;
        }

        $incomingIds = [];
        $newRows     = [];
        $errorsLog   = [];
        $sort        = self::SORT_STEP;

        foreach ($products as $product) {
            $dto = $this->buildProductRowDto($product, $storeId, $taxInclude, $sort);

            if ($dto === null) {
                // Товар не найден в каталоге
                $errorsLog[$this->productErrorKey('not_found', $product)] = [
                    'error'           => 'не удалось найти товар',
                    'lineProductId'   => (string)($product['lineProductId']   ?? 0),
                    'lineProductId1c' => (string)($product['lineProductId1c'] ?? ''),
                ];
                continue;
            }

            if ($dto->lineProductId > 0) {
                if (!isset($existingById[$dto->lineProductId])) {
                    $errorsLog[$this->productErrorKey('row_not_found', $product)] = [
                        'error'           => 'не удалось найти позицию',
                        'lineProductId'   => (string)$dto->lineProductId,
                        'lineProductId1c' => (string)($product['lineProductId1c'] ?? ''),
                    ];
                    continue;
                }

                $this->applyRowData($existingById[$dto->lineProductId], $dto->fields);
                $incomingIds[] = $dto->lineProductId;
            } else {
                $newRows[] = ProductRow::createFromArray($dto->fields);
            }

            $sort += self::SORT_STEP;
        }

        $finalRows = $this->buildFinalRows($existingById, $incomingIds, $newRows);
        $item->setProductRows($finalRows);

        $result = $factory->getUpdateOperation($item)->disableCheckAccess()->launch();

        if (!$result->isSuccess()) {
            $this->logger->error('Ошибка сохранения товарных строк', [
                'dealId' => $dealId,
                'errors' => $result->getErrorMessages(),
            ]);
            return [];
        }

        return $this->buildProductRowsResponse($dealId, $products, $errorsLog);
    }

    /**
     * Строит DTO товарной строки.
     * Возвращает null если товар не найден в каталоге Б24.
     */
    private function buildProductRowDto(array $product, int $storeId, bool $taxInclude, int $sort): ?ProductRowDto
    {
        $guid      = (string)($product['guid'] ?? '');
        $productId = $this->resolveProductId($guid);

        if ($productId === 0) {
            return null;
        }

        $el = CIBlockElement::GetByID($productId)->Fetch();
        if (!$el) {
            return null;
        }

        $lineProductId  = (int)($product['lineProductId'] ?? 0);
        $quantity       = $this->parseDecimal((string)($product['count']       ?? '1'));
        $totalSum       = $this->parseDecimal((string)($product['summa']       ?? '0'));
        $totalDiscount  = $this->parseDecimal((string)($product['skidka_ruch'] ?? '0'))
            + $this->parseDecimal((string)($product['skidka_auto'] ?? '0'));

        $safeQty        = $quantity > 0 ? $quantity : 1.0;
        $finalPriceUnit = $totalSum / $safeQty;
        $discountUnit   = $totalDiscount / $safeQty;
        $basePriceUnit  = $this->parseDecimal((string)($product['price'] ?? '0'));
        $vatRate        = (float)preg_replace('/[^0-9.]/', '', str_replace(',', '.', (string)($product['vat'] ?? '0')));

        $fields = [
            'PRODUCT_ID'       => $productId,
            'PRODUCT_NAME'     => $el['NAME'],
            'PRICE'            => $finalPriceUnit,
            'BASE_PRICE'       => $basePriceUnit,
            'QUANTITY'         => $quantity,
            'DISCOUNT_TYPE_ID' => Discount::MONETARY,
            'DISCOUNT_SUM'     => $discountUnit,
            'TAX_RATE'         => $vatRate,
            'TAX_INCLUDED'     => $taxInclude ? 'Y' : 'N',
            'STORE_ID'         => $storeId ?: null,
            'CUSTOMIZED'       => 'Y',
            'SORT'             => $sort,
        ];

        return new ProductRowDto(lineProductId: $lineProductId, fields: $fields);
    }

    /**
     * Применяет данные к существующей строке через сеттеры
     */
    private function applyRowData(ProductRow $row, array $data): void
    {
        $row->setProductId($data['PRODUCT_ID']);
        $row->setProductName($data['PRODUCT_NAME']);
        $row->setPrice($data['PRICE']);
        $row->setQuantity($data['QUANTITY']);
        $row->setDiscountTypeId($data['DISCOUNT_TYPE_ID']);
        $row->setDiscountSum($data['DISCOUNT_SUM']);
        $row->setTaxRate($data['TAX_RATE']);
        $row->setTaxIncluded($data['TAX_INCLUDED']);
        $row->setCustomized($data['CUSTOMIZED']);
        $row->setSort($data['SORT']);
    }

    /**
     * Собирает финальный список строк: обновлённые + новые.
     * Строки не попавшие в $incomingIds — не включаются (Битрикс их удалит).
     *
     * @param ProductRow[] $existingById
     * @param int[]        $incomingIds
     * @param ProductRow[] $newRows
     * @return ProductRow[]
     */
    private function buildFinalRows(array $existingById, array $incomingIds, array $newRows): array
    {
        $finalRows = array_values(
            array_filter(
                $existingById,
                fn($row, $id) => in_array($id, $incomingIds, true),
                ARRAY_FILTER_USE_BOTH
            )
        );

        return array_merge($finalRows, $newRows);
    }

    /**
     * Строит ответ с b24_id каждой товарной строки после сохранения.
     */
    private function buildProductRowsResponse(int $dealId, array $requestProducts, array $errorsLog): array
    {
        $incomingIds = array_filter(
            array_map(fn($p) => (int)($p['lineProductId'] ?? 0), $requestProducts),
            fn($id) => $id > 0
        );

        // Новые строки — те, чей ID не был в запросе
        $savedRows      = CCrmProductRow::LoadRows('D', $dealId) ?: [];
        $newlySavedRows = array_values(array_filter(
            $savedRows,
            fn($row) => !in_array((int)$row['ID'], $incomingIds, true)
        ));
        usort($newlySavedRows, fn($a, $b) => (int)$a['SORT'] <=> (int)$b['SORT']);

        $response      = [];
        $newRowPointer = 0;

        foreach ($requestProducts as $product) {
            $lineProductId = (int)($product['lineProductId'] ?? 0);
            $guid          = (string)($product['guid'] ?? '');

            $errorKey = $this->findErrorKey($errorsLog, $lineProductId, $guid);
            if ($errorKey !== null) {
                $response[] = $errorsLog[$errorKey];
                continue;
            }

            if ($lineProductId > 0) {
                $response[] = [
                    'b24_id'          => $lineProductId,
                    'lineProductId'   => (int)$lineProductId,
                    'lineProductId1c' => (int)($product['lineProductId1c'] ?? ''),
                ];
            } elseif (isset($newlySavedRows[$newRowPointer])) {
                $response[] = [
                    'b24_id'          => (int)$newlySavedRows[$newRowPointer]['ID'],
                    'lineProductId'   => 0,
                    'lineProductId1c' => (int)($product['lineProductId1c'] ?? ''),
                ];
                $newRowPointer++;
            }
        }

        return $response;
    }

    // ------------------------------------------------------------------
    // Промежуточная таблица
    // ------------------------------------------------------------------

    private function persistProductRows(int $dealId, array $requestProducts, array $productRowsResponse): void
    {
        // Индекс: lineProductId1c → b24_line_product_id из ответа
        $b24IdByLineId1c = [];
        foreach ($productRowsResponse as $row) {
            if (isset($row['lineProductId1c'], $row['b24_id'])) {
                $b24IdByLineId1c[(string)$row['lineProductId1c']] = (int)$row['b24_id'];
            }
        }

        $incomingLineIds1c = array_filter(
            array_map(fn($p) => (string)($p['lineProductId1c'] ?? ''), $requestProducts),
            fn($id) => $id !== ''
        );

        $this->deleteObsoleteProductRows($dealId, array_values($incomingLineIds1c));

        foreach ($requestProducts as $product) {
            $lineId1c  = (string)($product['lineProductId1c'] ?? '');
            $b24LineId = $b24IdByLineId1c[$lineId1c] ?? 0;

            try {
                RusgeocomOrderProductTable::upsert($dealId, $b24LineId, $product);
            } catch (\Throwable $e) {
                $this->logger->error('Ошибка upsert в промтаблицу', [
                    'dealId'    => $dealId,
                    'lineId1c'  => $lineId1c,
                    'error'     => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Удаляет из промтаблицы строки которых нет в текущем запросе.
     * Если $incomingLineIds1c пуст — удаляет все строки сделки.
     */
    private function deleteObsoleteProductRows(int $dealId, array $incomingLineIds1c): void
    {
        $filter = ['=DEAL_ID' => $dealId];

        if (!empty($incomingLineIds1c)) {
            $filter['!LINE_PRODUCT_ID_1C'] = $incomingLineIds1c;
        }

        try {
            $rows = RusgeocomOrderProductTable::getList([
                'filter' => $filter,
                'select' => ['ID'],
            ]);

            while ($row = $rows->fetch()) {
                RusgeocomOrderProductTable::delete($row['ID']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Ошибка удаления устаревших строк из промтаблицы', [
                'dealId' => $dealId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Формирование ответа
    // ------------------------------------------------------------------

    private function buildResponse(
        int                  $savedId,
        string               $guid,
        bool                 $isNew,
        ResolvedRelationsDto $resolved,
        array                $productRows
    ): array {
        return [
            'b24_id'         => $savedId,
            'guid'           => $guid,
            'status'         => $isNew ? 'created' : 'updated',
            'company_id'     => $resolved->companyId,
            'organisation'   => $resolved->organisationId,
            'agreement'      => $resolved->agreementId,
            'warehouseId'    => $resolved->warehouseId,
            'manager_id'     => $resolved->managerId,
            'contacts_id'    => $resolved->contactId,
            'businessRegion' => $resolved->businessRegionId,
            'methodDelivery' => $resolved->methodDeliveryId,
            'products'       => $productRows,
        ];
    }

    // ------------------------------------------------------------------
    // Вспомогательные
    // ------------------------------------------------------------------

    private function resolveProductId(string $guid): int
    {
        if (empty($guid)) {
            return 0;
        }

        Loader::includeModule('iblock');

        $row = ElementTable::getRow([
            'select' => ['ID'],
            'filter' => ['IBLOCK_ID' => self::IBLOCK_ID, '=XML_ID' => $guid],
        ]);

        return $row ? (int)$row['ID'] : 0;
    }

    private function parseDecimal(string $value): float
    {
        $clean = preg_replace('/\s+/', '', $value);
        $clean = str_replace(',', '.', $clean);
        $clean = preg_replace('/[^0-9.\-]/', '', $clean);

        return (float)$clean;
    }

    private function getFactory(): Factory
    {
        $factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
        if (!$factory) {
            throw new \RuntimeException('Фабрика сделок не найдена');
        }

        return $factory;
    }

    /**
     * Уникальный ключ ошибки для errorsLog
     */
    private function productErrorKey(string $type, array $product): string
    {
        return sprintf('%s_%s_%s', $type, $product['guid'] ?? '', $product['lineProductId'] ?? 0);
    }

    /**
     * Ищет ключ ошибки в errorsLog по lineProductId или guid
     */
    private function findErrorKey(array $errorsLog, int $lineProductId, string $guid): ?string
    {
        foreach ($errorsLog as $key => $errorData) {
            if ($lineProductId > 0 && $errorData['lineProductId'] === (string)$lineProductId) {
                return $key;
            }
            if ($lineProductId === 0 && str_contains($key, 'not_found_' . $guid)) {
                return $key;
            }
        }

        return null;
    }
}