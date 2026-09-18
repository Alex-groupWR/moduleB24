<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\StoreProductTable;
use Illuminate\Support\Str;
use Logema\Utils\DataAccess\IblockHelper;
use Logema\Utils\Multithreading\PoolChunkableTaskProcessor;
use Rusgeocom\Rusgeocom\Cache\Redis;
use Rusgeocom\Rusgeocom\Catalog\Entities\Badge;
use Rusgeocom\Rusgeocom\Catalog\Entities\PriceType;
use Rusgeocom\Rusgeocom\Catalog\Entities\SortBlock;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;
use Rusgeocom\Rusgeocom\Geoip\GeoLocation;
use Rusgeocom\Rusgeocom\Import\EsolExcelImport;
use Rusgeocom\Rusgeocom\Import\RestsFill;
use Rusgeocom\Rusgeocom\Sale\Statistics\SortHelp;

class Sort
{
	private static $storeProductsData = [];
	private static $pricesData = [];

	public static function onPriceUpdate($id, $fields)
	{
		$productId = $fields['PRODUCT_ID'];
		self::patchSortPrice($productId);
	}

	public static function onBeforePriceDelete($id)
	{
		$price = PriceTable::getById($id)->fetch();
		if ($price['PRODUCT_ID']) {
			static::$pricesData[$id] = $price['PRODUCT_ID'];
		}
	}

	public static function onPriceDelete($id)
	{
		if (static::$pricesData[$id]) {
			self::patchSortPrice(static::$pricesData[$id]);
		}
	}

	public static function onProductPriceDelete($productId)
	{
		self::patchSortPrice($productId);
	}

	public static function patchSortPrice($productId)
	{
		$products = \CCatalogSku::getProductList([$productId], CATALOG_OFFERS_IBLOCK_ID);
		if ($products) {
			$productId = $products[$productId]['ID'];
		}

		$basePriceType = PriceTypes::getBasePriceType();
		$priceTypes = PriceTypes::getActiveTypes();
		$priceFields = PriceTypes::getPriceFields();

		$product = \CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => CATALOG_IBLOCK_ID,
				'ID' => $productId,
			],
			false,
			false,
			array_merge(
				[
					'ID',
					'IBLOCK_ID',
					'PROPERTY_PRICE_ON_REQUEST',
					'PROPERTY_PRICE_MODIFIER',
				],
				$priceFields
			)
		)->Fetch();

		if (!$product) {
			return;
		}

		$offer = \CIBlockElement::GetList(
			['SORT' => 'asc'],
			[
				'IBLOCK_ID' => CATALOG_OFFERS_IBLOCK_ID,
				'PROPERTY_CML2_LINK' => $productId,
				'PROPERTY_POVERKA' => false,
				'PROPERTY_CHECK' => false,
				'!catalog_PRICE_1' => false,
				'ACTIVE' => 'Y',
			],
			false,
			false,
			array_merge(
				[
					'ID',
					'IBLOCK_ID',
					'PROPERTY_CML2_LINK',
				],
				$priceFields
			)
		)->Fetch();

		$helperEntry = SortHelp::getActualEntry($productId);
		$helperEntry->fill();

		$priceModifier = (int)$product['PROPERTY_PRICE_MODIFIER_VALUE'] ?? 0;
		$actualProduct = $offer ?: $product;
		if ($product['PROPERTY_PRICE_ON_REQUEST_VALUE']) {
			foreach ($priceTypes as $priceType) {
				if ($helperEntry->has($priceType->getSortField())) {
					$helperEntry->unset($priceType->getSortField());
				}
			}
		} elseif ($actualProduct) {
			$basePrice = null;
			if (isset($actualProduct[$basePriceType->getPriceProperty()])) {
				$basePrice = Price::convertToBaseCurrency(
					max($actualProduct[$basePriceType->getPriceProperty()] + $priceModifier, 0),
					$actualProduct[$basePriceType->getCurrencyProperty()]
				);
			}

			foreach ($priceTypes as $priceType) {
				$currentPrice = static::getPriceValueByType($actualProduct, $priceType);

				if (!$priceType->isBasePrice() && isset($actualProduct['PROPERTY_CML2_LINK_VALUE'])) {
					//Мы не заполняем оптовые цены и скидки в торговых предложениях - смотрим в товаре
					$currentPrice = static::getPriceValueByType($product, $priceType);
				}

				$currentPrice ??= $basePrice;

				if ($currentPrice !== $helperEntry->get($priceType->getSortField())) {
					$helperEntry->set($priceType->getSortField(), $currentPrice);
				}
			}
		}

		$isChanged = false;
		foreach ($priceTypes as $priceType) {
			if ($helperEntry->isChanged($priceType->getSortField())) {
				$isChanged = true;
				break;
			}
		}

		if ($isChanged) {
			$helperEntry->save();
		}
	}

	private static function getPriceValueByType(array $offerOrProduct, PriceType $priceType): ?float
	{
		return isset($offerOrProduct[$priceType->getPriceProperty()])
			? Price::convertToBaseCurrency(
				$offerOrProduct[$priceType->getPriceProperty()],
				$offerOrProduct[$priceType->getCurrencyProperty()],
			)
			: null;
	}

	public static function onAfterIBlockElementUpdate(&$arFields)
	{
		if (in_array($arFields['IBLOCK_ID'], [CATALOG_IBLOCK_ID, CATALOG_OFFERS_IBLOCK_ID])) {
			static::patchSortPrice($arFields['ID']);
		}

		if ($arFields['IBLOCK_ID'] != CATALOG_IBLOCK_ID) {
			return;
		}

		static::checkAndUpdateProductSort($arFields['ID']);
	}

	public static function onAfterIBlockElementAdd(&$arFields)
	{
		if ($arFields['IBLOCK_ID'] != CATALOG_IBLOCK_ID) {
			return;
		}

		static::checkAndUpdateProductSort($arFields['ID']);
		static::patchSortPrice($arFields['ID']);
	}

	public static function onAfterIBlockElementDelete($arFields)
	{
		if (in_array($arFields['IBLOCK_ID'], [CATALOG_IBLOCK_ID, CATALOG_OFFERS_IBLOCK_ID])) {
			static::patchSortPrice($arFields['ID']);
		}
	}

	public static function onAfterIBlockElementSetPropertyValuesEx($elementId, $iblockId, array $propertyValues, array $flags)
	{
		if (in_array($iblockId, [CATALOG_IBLOCK_ID, CATALOG_OFFERS_IBLOCK_ID])) {
			static::checkAndUpdateProductSort($elementId);
		}
	}

	public static function onStoreProductUpdate($ID, $arFields)
	{
		static::checkAndUpdateProductSort($arFields['PRODUCT_ID']);
		self::updateStatisticsTables($arFields['PRODUCT_ID'], $arFields['STORE_ID'], $arFields['AMOUNT']);
	}

	public static function onStoreProductAdd($ID, $arFields)
	{
		self::updateStatisticsTables($arFields['PRODUCT_ID'], $arFields['STORE_ID'], $arFields['AMOUNT']);
	}

	public static function onBeforeStoreProductDelete($id)
	{
		$product = StoreProductTable::getById($id)->fetch();

		if ($product) {
			static::$storeProductsData[$id] = $product;
		}
	}

	public static function onStoreProductDelete($id)
	{
		$product = static::$storeProductsData[$id];

		if ($product) {
			self::updateStatisticsTables($product['PRODUCT_ID'], $product['STORE_ID']);
		}
	}

	/**
	 * Заполняет свойство "Группа для сортировки" для товара по ID
	 *
	 * @param $productId
	 */
	public static function checkAndUpdateProductSort($productId)
	{
		if (!$productId) {
			return;
		}

		$domains = [];
		foreach (GeoLocation::getCities() as $city){
			$domains[] = strtoupper($city['CODE']);
		}

		$select = [
			'ID',
			'IBLOCK_ID',
			'PROPERTY_PRICE_ON_REQUEST',
			'PROPERTY_SHOW_OUT_OF_STOCK',
			'PROPERTY_ACSESSUAR_SORT',
			'PROPERTY_POPULAR_COEFFICIENT',
			'PROPERTY_FAKE_SOLD',
			'PROPERTY_TRANSIT_POSTAV',
			'SORT',
			'PROPERTY_IS_PROMOTED_PRODUCT',
			'PROPERTY_BADGES',
			'PROPERTY_BRAND_REF',
		];
		foreach ($domains as $domain){
			$select[] = 'PROPERTY_POPULAR_COEFFICIENT_' . $domain;
		}
		$product = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementById($productId, $select);
		if (!$product) {
			return;
		}

		if ($product['PROPERTY_POPULAR_COEFFICIENT_VALUE']) {
			$product['PROPERTY_POPULAR_COEFFICIENT_VALUE'] = str_replace(',', '.',
				$product['PROPERTY_POPULAR_COEFFICIENT_VALUE']);
		}
		foreach ($domains as $domain){
			if ($product['PROPERTY_POPULAR_COEFFICIENT_' . $domain . '_VALUE']) {
				$product['PROPERTY_POPULAR_COEFFICIENT_' . $domain . '_VALUE'] = str_replace(',', '.',
					$product['PROPERTY_POPULAR_COEFFICIENT_' . $domain . '_VALUE']);
			}
		}

		$sortRow = SortHelp::getActualEntry($productId);
		foreach ($domains as $domain) {
			$currentField = 'POPULAR_COEFFICIENT_' . $domain;
			$sortRow->set($currentField, (float)($product['PROPERTY_' . $currentField . '_VALUE'] ?? 1));
		}

		$badges = ($product['PROPERTIES']['BADGES']['VALUE'] ?? []) ?: [];
		$sortRow->setByRequest((bool)$product['PROPERTY_PRICE_ON_REQUEST_VALUE'])
			->setIsAccessory((bool)$product['PROPERTY_ACSESSUAR_SORT_VALUE'])
			->setIbElementSort((int)$product['SORT'])
			->setHasDealerTransit($product['PROPERTY_TRANSIT_POSTAV_VALUE'] > 0)
			->setFakeSold((int)$product['PROPERTY_FAKE_SOLD_VALUE'])
			->setPopularCoefficient((float)($product['PROPERTY_POPULAR_COEFFICIENT_VALUE'] ?? 1))
			->setIsPromotedProduct($product['PROPERTY_IS_PROMOTED_PRODUCT_VALUE'] === 'Y')
			->setIsActionBadgeSet(in_array(Badge::BADGE_ACTION_CODE, $badges, true))
			->setIsRgk(mb_strtolower($product['PROPERTY_BRAND_REF_VALUE']) === 'rgk')
			->setIsAmo(mb_strtolower($product['PROPERTY_BRAND_REF_VALUE']) === 'amo')
			->save();
	}

	public static function extractSortBlockFromUrl(\Rusgeocom\Rusgeocom\Types\Uri $uri): SortBlock
	{
		$sort = new SortBlock();

		$by = $uri->getParam('SORT_BY', '');
		$order = $uri->getParam('ORDER_BY', '');

		if ($by){
			$sort->setActive($by, $order);
		}

		return $sort;
	}

	protected static function updateStatisticsTables($productId, $storeId, $amount = 0)
	{
		$statisticsHelper = SortHelp::getActualEntry($productId);

		try {

			if (Availability::hasMultiplyUtCodes($productId)){
				$amount = Availability::calculateStocksForProduct($productId)->getStoreAmount($storeId);
			}

			$actualAmount = $statisticsHelper->getRestAmount($storeId);

			if ($amount != $actualAmount && (!EsolExcelImport::isImportInProgress() || $storeId != DLR_STORE_ID || $amount > 0)) {
				$statisticsHelper->setRestAmount($storeId, $amount);
			}

			$statisticsHelper->save();
		} catch (\Exception $exception) {
		}
	}

	public static function refillSortHelpTable(): void
	{
		$task = new RestsFill();
		$processor = new PoolChunkableTaskProcessor(1, []);
		$processor->process($task);
		Redis::getInstance()->clearAllNuxtCache();
	}
}