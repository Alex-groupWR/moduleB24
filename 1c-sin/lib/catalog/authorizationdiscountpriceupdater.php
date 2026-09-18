<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\Model\Price as BitrixPrice;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\EventManager;
use Bitrix\Main\Type\DateTime;
use CIBlockElement;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Entities\PriceType;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;
use Rusgeocom\Rusgeocom\Catalog\Tables\PriceRecalculationsTable;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;

class AuthorizationDiscountPriceUpdater
{
	private const DISCOUNT_USER_CODE = 'INDIVIDUAL_DISCOUNT';
	private const DISCOUNT_COMPANY_CODE = 'COMPANY_DISCOUNT';
	private const MINUTES_BEFORE_EXECUTE = 1;

	public static array $discountProperties = [];
	public static array $offers = [];
	public static array $changes = [];

	public static function bindEvents(): void
	{
		$eventManager = EventManager::getInstance();
		$eventManager->addEventHandlerCompatible(
			'iblock',
			'OnBeforeIBlockElementUpdate',
			[__CLASS__, 'onBeforeIBlockElementUpdate']
		);
		$eventManager->addEventHandlerCompatible(
			'iblock',
			'OnAfterIBlockElementUpdate',
			[__CLASS__, 'onAfterIBlockElementUpdate']
		);
		$eventManager->addEventHandlerCompatible(
			'catalog',
			'OnPriceUpdate',
			[__CLASS__, 'OnPriceUpdate']
		);
	}

	public static function OnPriceUpdate($id, array $fields = []): void
	{
		if ($fields['CATALOG_GROUP_ID'] !== BASE_PRICE_ID) {
			return;
		}

		$elementId = $fields['PRODUCT_ID'];

		$iBlockId = (int)ElementTable::query()
			->addSelect('IBLOCK_ID')
			->where('ID', $elementId)
			->exec()
			->fetch()['IBLOCK_ID'] ?? 0;

		if (!static::isCatalogIBlock($iBlockId)) {
			return;
		}

		$productId = (int)$elementId;
		if ($iBlockId === CATALOG_OFFERS_IBLOCK_ID) {
			$productIds = Catalog::getProductIdsByOfferIds([$fields['PRODUCT_ID']]);
			$productId = (int)current($productIds);
		}

		if (!$productId) {
			return;
		}

		$discounts = static::getDiscountValues($productId);
		foreach (static::getDiscountPropertyIds() as $code => $propertyId) {
			$priceType = PriceTypes::getByName(static::getDiscountPriceTypeName($code));
			if (isset(static::$changes[$productId])) {
				$existing = Arr::first(static::$changes[$productId], static function($item) use ($priceType) {
					/** @var PriceType $currentPriceType */
					$currentPriceType = $item['priceType'];
					return $currentPriceType->isEqual($priceType);
				});

				if ($existing) {
					continue;
				}
			}
			static::addToQueue($elementId, $iBlockId, $priceType, $discounts[$propertyId]);
		}
	}

	public static function onBeforeIBlockElementUpdate(&$fields): void
	{
		if (!static::isCatalogIBlock((int)$fields['IBLOCK_ID'])) {
			return;
		}

		$id = (int)$fields['ID'];
		$oldValues = static::getDiscountValues($id);
		foreach (static::getDiscountPropertyIds() as $code => $propertyId) {
			$currentProperty = $fields['PROPERTY_VALUES'][$propertyId] ?? [];
			$currentValue = array_values($currentProperty)[0]['VALUE'] ?? '';
			$oldValue = $oldValues[$propertyId] ?? '';

			if ($currentValue !== $oldValue) {
				$priceType = PriceTypes::getByName(static::getDiscountPriceTypeName($code));
				static::$changes[$id][] = [
					'iBlockId' => (int)$fields['IBLOCK_ID'],
					'priceType' => $priceType,
					'discountValue' => $currentValue,
				];
			}
		}
	}

	public static function onAfterIBlockElementUpdate(&$fields): void
	{
		if (!static::isCatalogIBlock((int)$fields['IBLOCK_ID'])) {
			return;
		}

		$id = (int)$fields['ID'];
		$changes = static::$changes[$id] ?? [];
		foreach ($changes as $options) {
			/** @var PriceType $priceType */
			$priceType = $options['priceType'];
			if ($priceType->isBasePrice()) {
				return;
			}

			static::addToQueue($id, $options['iBlockId'], $priceType, $options['discountValue']);
		}
	}

	public static function processChanges(int $limit = 100): void
	{
		$iterator = PriceRecalculationsTable::query()
			->setSelect(['*'])
			->where('DATE_CREATED', '<', (new DateTime())->add('-' . static::MINUTES_BEFORE_EXECUTE . ' minutes'))
			->setOrder(['ID' => 'ASC'])
			->setLimit($limit)
			->exec();

		while ($task = $iterator->fetch()) {
			static::updateElementPrice(
				(int)$task['IBLOCK_ELEMENT_ID'],
				(int)$task['IBLOCK_ID'],
				PriceTypes::getById((int)$task['CATALOG_GROUP_ID']),
				$task['DISCOUNT_VALUE']
			);

			PriceRecalculationsTable::delete($task['ID']);
		}
	}

	public static function updateAll(): void
	{
		$conditions = [];
		$fields = [];
		foreach (static::getMappings() as $propertyName => $priceTypeName) {
			$priceType = PriceTypes::getByName($priceTypeName);
			$property = 'PROPERTY_' . $propertyName;
			$conditions['!' . $property] = false;
			$conditions['!' . $priceType->getPriceProperty()] = false;
			$fields[$property] = $priceType;
		}

		$iterator = CIBlockElement::GetList(
			['ID' => 'ASC'],
			[
				'ACTIVE' => 'Y',
				'IBLOCK_ID' => CATALOG_IBLOCK_ID,
				array_merge(['LOGIC' => 'OR'], $conditions),
			],
			false,
			false,
			array_merge(['ID', 'IBLOCK_ID'], array_keys($fields))
		);

		while ($product = $iterator->fetch()) {
			foreach ($fields as $property => $priceType) {
				static::updateElementPrice(
					$product['ID'],
					$product['IBLOCK_ID'],
					$priceType,
					$product[$property] ?: ''
				);
			}
		}
	}

	public static function updateElementPrice(
		int $id,
		int $iBlockId,
		PriceType $priceType,
		string $discountValue
	): void {
		$currentId = $id;
		if ($iBlockId === CATALOG_IBLOCK_ID) {
			static::updateProductDiscountPrice($currentId, $priceType, $discountValue);
		}
	}

	private static function getPriceModifier(int $productId): int
	{
		return (int)IblockElementPropertyTable::query()
			->addSelect('VALUE')
			->where('IBLOCK_PROPERTY.CODE', Price::PRICE_MODIFIER_CODE)
			->where('IBLOCK_ELEMENT_ID', $productId)
			->fetch()['VALUE'] ?? 0;
	}

	private static function addToQueue(int $elementId, int $iBlockId, PriceType $priceType, string $discountValue): void
	{
		PriceRecalculationsTable::add(
			[
				'IBLOCK_ELEMENT_ID' => $elementId,
				'IBLOCK_ID' => $iBlockId,
				'CATALOG_GROUP_ID' => $priceType->getId(),
				'DISCOUNT_VALUE' => $discountValue ?: '0',
			]
		);
	}

	private static function getMainOfferId(int $productId): int
	{
		$select = [
			'ID',
			'CATALOG_PRICE_1',
			'CATALOG_CURRENCY_1',
			'PROPERTY_CML2_LINK',
		];
		$filter = [
			'=PROPERTY_CML2_LINK' => $productId,
			'=ACTIVE' => 'Y',
			'=PROPERTY_CHECK' => false,
		];
		$sort = ['SORT' => 'ASC'];
		$offer = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)
			->getElementByFilter($filter, $select, $sort);

		if (!$offer) {
			return 0;
		}

		static::$offers[(int)$offer['ID']] = [
			'ID' => (int)$offer['ID'],
			'PRICE' => (float)$offer['CATALOG_PRICE_1'],
			'CURRENCY' => $offer['CATALOG_CURRENCY_1'],
			'PRODUCT_ID' => (int)$offer['PROPERTY_CML2_LINK'],
		];

		return (int)$offer['ID'];
	}

	private static function updateProductDiscountPrice(
		int $productId,
		PriceType $priceType,
		string $discountValue
	): void {
		$basePrice = static::getBasePrice($productId);
		$priceModifier = static::getPriceModifier($productId);
		static::updatePrice($productId, $basePrice, $priceModifier, $priceType, $discountValue);
	}

	private static function updatePrice(
		int $elementId,
		array $basePrice,
		int $priceModifier,
		PriceType $priceType,
		string $discountValue
	): void {
		if (!$basePrice) {
			return;
		}

		$priceId = static::getPriceId($elementId, $priceType);
		if (!trim($discountValue)) {
			if ($priceId) {
				BitrixPrice::delete($priceId);
			}

			return;
		}

		$convertedBasePrice = Price::convertToBaseCurrency(
			max($basePrice['PRICE'] + $priceModifier, 0),
			$basePrice['CURRENCY']
		);

		$data = [
			'PRODUCT_ID' => $elementId,
			'CATALOG_GROUP_ID' => $priceType->getId(),
			'PRICE' => static::calculatePriceWithDiscount($convertedBasePrice, $discountValue),
			'CURRENCY' => Price::CURRENCY_RUB,
		];

		if ($priceId) {
			BitrixPrice::update($priceId, $data);
		} else {
			BitrixPrice::add($data);
		}
	}

	/**
	 * Рассчитывает цену со скидкой с поддержкой дробных процентов
	 *
	 * @param float  $price
	 * @param string $discount
	 * @return float
	 */
	private static function calculatePriceWithDiscount(float $price, string $discount): float
	{
		$hasPercent = Str::contains($discount, '%');
		$discount = Str::replace(',', '.', $discount);
		$discount = Str::replaceLast('%', '', $discount);
		$discountValue = abs(floatval($discount));

		return $discount
			? max(
				$hasPercent
					? $price * (100 - $discountValue) / 100
					: $price - $discountValue,
				0
			)
			: $price;
	}

	private static function getBasePrice(int $productOrVariantId): array
	{
		return PriceTable::query()
			->addSelect('PRICE')
			->addSelect('CURRENCY')
			->where('PRODUCT_ID', $productOrVariantId)
			->where('CATALOG_GROUP_ID', BASE_PRICE_ID)
			->exec()
			->fetch() ?? [];
	}

	private static function getPriceId(int $productOrVariantId, PriceType $priceType): int
	{
		return (int)PriceTable::query()
			->addSelect('ID')
			->where('PRODUCT_ID', $productOrVariantId)
			->where('CATALOG_GROUP_ID', $priceType->getId())
			->exec()
			->fetch()['ID'] ?? 0;
	}

	private static function getDiscountValues(int $productId): array
	{
		$oldValues = [];

		$propertyIds = array_values(static::getDiscountPropertyIds());

		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_PROPERTY_ID')
			->addSelect('VALUE')
			->where('IBLOCK_ELEMENT_ID', $productId)
			->whereIn('IBLOCK_PROPERTY_ID', $propertyIds)
			->exec();

		while ($oldValue = $iterator->fetch()) {
			$oldValues[(int)$oldValue['IBLOCK_PROPERTY_ID']] = $oldValue['VALUE'];
		}

		$default = array_fill_keys($propertyIds, '');

		return $oldValues + $default;
	}

	private static function isCatalogIBlock(int $iBlockId): bool
	{
		return in_array($iBlockId, [CATALOG_IBLOCK_ID]);
	}

	private static function getDiscountPropertyIds(): array
	{
		if (!static::$discountProperties) {
			$iterator = PropertyTable::query()
				->addSelect('ID')
				->addSelect('CODE')
				->where('IBLOCK_ID', CATALOG_IBLOCK_ID)
				->whereIn('CODE', [static::DISCOUNT_USER_CODE, static::DISCOUNT_COMPANY_CODE])
				->exec();

			while ($property = $iterator->fetch()) {
				static::$discountProperties[$property['CODE']] = (int)$property['ID'];
			}
		}

		return static::$discountProperties;
	}

	private static function getDiscountPriceTypeName(string $discountCode): ?string
	{
		return static::getMappings()[$discountCode] ?? null;
	}

	private static function getMappings(): array
	{
		return [
			static::DISCOUNT_USER_CODE => PriceTypes::USER_PRICE_CODE,
			static::DISCOUNT_COMPANY_CODE => PriceTypes::COMPANY_PRICE_CODE,
		];
	}
}