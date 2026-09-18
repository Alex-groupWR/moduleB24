<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Rusgeocom\Rusgeocom\Catalog\Entities\ProductStocks;
use Bitrix\Catalog\StoreProductTable;
use Rusgeocom\Rusgeocom\Geoip\BranchCity;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;
use Rusgeocom\Rusgeocom\Stores\StoreService;

class Availability
{
	/**
	 * @param array $productIds
	 * @return array
	 *
	 * Принимает массив ID товаров, возвращает массив ID товаров, которые есть хотя бы на одном складе или в транзите
	 */
	public static function getInStockProductIds(
		array $productIds,
		bool $isNeedCheckTransit = false,
		?BranchCity $branch = null
	): array
	{
		return $isNeedCheckTransit
			? static::getInStockProductIdsWithCheckTransit($productIds)
			: static::getInStockProductIdsWithoutCheckTransit($productIds, $branch);
	}

	private static function getInStockProductIdsWithCheckTransit(array $productIds): array
	{
		$resultIds = [];

		$iterator = IblockElementPropertyTable::query()
			->addFilter('=IBLOCK_PROPERTY.CODE', 'AVAILABLE_IN_STOCK')
			->addFilter('=IBLOCK_ELEMENT_ID', $productIds)
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->exec();
		while ($row = $iterator->fetch()) {
			if ($row['VALUE']) {
				$resultIds[$row['IBLOCK_ELEMENT_ID']] = $row['VALUE'];
			}
		}

		return $resultIds;
	}

	private static function getInStockProductIdsWithoutCheckTransit(array $productIds, ?BranchCity $branch = null): array
	{
		$resultIds = [];

		$stocks = static::calculateStocksForProducts($productIds, $branch);
		foreach ($stocks as $productId => $productsStocks){
			if ($productsStocks->isProductAvailable()) {
				$resultIds[$productId] = $productsStocks->isProductAvailable();
			}
		}

		return $resultIds;
	}

	/**
	 * Массив складов для селекта
	 *
	 * @return array
	 */
	public static function getStoreAmountSelect()
	{
		$select = [];
		foreach (StoreService::getInstance()->getIds() as $storeId){
			$select[] = 'CATALOG_STORE_AMOUNT_' . $storeId;
		}
		return $select;
	}

	/**
	 * Проверяет наличие товара хотябы на одном складе
	 *
	 * @param array $element
	 * @return bool
	 */
	public static function isElementStoreAvailable($element)
	{
		foreach (StoreService::getInstance()->getIds() as $storeId){
			if ($element['CATALOG_STORE_AMOUNT_' . $storeId] > 0){
				return true;
			}
		}

		return false;
	}

	public static function calculateStocksForProduct(int $productId): ProductStocks
	{
		return static::calculateStocksForProducts([$productId])[$productId];
	}

	/**
	 * @param int[] $productIds
	 * @return ProductStocks[]
	 */
	public static function calculateStocksForProducts(array $productIds, ?BranchCity $branch = null): array
	{
		if (!$productIds){
			return [];
		}

		$calculator = new StocksCalculator($productIds);
		return $calculator->calculate();
	}

	public static function calculateStocksForProductsByProductType(array $products): array
	{
		$result = [];
		$complectProductIds = [];
		$regularProductIds = [];

		foreach ($products as $key=>$product) {
			if ($product['isComplect']) {
				$complectProductIds[] = $key;
			} else {
				$regularProductIds[] = $key;
			}
		}

		if ($complectProductIds) {
			$result += static::getStockAmountForComplect($complectProductIds);
		}
		if ($regularProductIds) {
			$calculator = new StocksCalculator($regularProductIds);
			$result += $calculator->calculate();
		}

		return $result;
	}

	private static function getStockAmountForComplect(array $complectProductIds): array
	{
		$data = [];
		$return = [];

		$iterator = StoreProductTable::query()
			->addSelect('STORE_ID')
			->addSelect('PRODUCT_ID')
			->addSelect('AMOUNT')
			->addFilter('=PRODUCT_ID', $complectProductIds)
			->exec();
		while ($row = $iterator->fetch()){
			$data[$row['PRODUCT_ID']][$row['STORE_ID']] = $row['AMOUNT'];
		}

		foreach ($data as $productId => $stores) {
			$return[$productId] = new ProductStocks($stores);
		}

		return $return;
	}

	public static function getDealerTransitsForProducts(array $productIds): array
	{
		if (!$productIds) {
			return [];
		}
		$dealerTransits = [];

		$iterator = IblockElementPropertyTable::query()
			->whereIn('IBLOCK_ELEMENT_ID', $productIds)
			->where('IBLOCK_PROPERTY.CODE', 'DEALER_TRANSIT')
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->exec();
		while ($row = $iterator->fetch()) {
			$dealerTransits[$row['IBLOCK_ELEMENT_ID']] = json_decode($row['VALUE'], true);
		}

		return $dealerTransits;
	}

	public static function getReservesAndTransitsForProducts(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$result = [];

		$stores = StoreService::getInstance()->getAll();
		$propCodeToStoreIdMap = [];
		foreach ($stores as $store){
			$propCodeToStoreIdMap[$store->getReservePropCode()] = $store->getId();
			$propCodeToStoreIdMap[$store->getTransitPropCode()] = $store->getId();
		}

		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addSelect('IBLOCK_PROPERTY.CODE', 'PROPERTY_CODE')
			->addFilter('IBLOCK_ELEMENT_ID', $productIds)
			->addFilter('IBLOCK_PROPERTY.CODE', array_keys($propCodeToStoreIdMap))
			->addFilter('>VALUE', 0)
			->exec();
		while ($row = $iterator->fetch()){
			$storeId = (int)$propCodeToStoreIdMap[$row['PROPERTY_CODE']];
			if (strpos($row['PROPERTY_CODE'], 'TRANSIT') !== false){
				$result[$row['IBLOCK_ELEMENT_ID']][$storeId]['TRANSIT'] = (int)$row['VALUE'];
			}
			else{
				$result[$row['IBLOCK_ELEMENT_ID']][$storeId]['RESERVE'] = (int)$row['VALUE'];
			}
		}

		return $result;
	}

	public static function getTransitsForProducts(array $productIds): array
	{
		if (!$productIds) {
			return [];
		}

		$result = [];

		$stores = StoreService::getInstance()->getAll();
		$propCodeToStoreIdMap = [];
		foreach ($stores as $store) {
			$propCodeToStoreIdMap[$store->getTransitPropCode()] = $store->getId();
		}

		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addSelect('IBLOCK_PROPERTY.CODE', 'PROPERTY_CODE')
			->addFilter('IBLOCK_ELEMENT_ID', $productIds)
			->addFilter('IBLOCK_PROPERTY.CODE', array_keys($propCodeToStoreIdMap))
			->addFilter('>VALUE', 0)
			->exec();
		while ($row = $iterator->fetch()) {
			$storeId = (int)$propCodeToStoreIdMap[$row['PROPERTY_CODE']];
			$result[(int)$row['IBLOCK_ELEMENT_ID']][$storeId] = (int)$row['VALUE'];
		}

		return $result;
	}

	public static function hasMultiplyUtCodes(int $productId): bool
	{
		return !!IblockElementPropertyTable::query()
			->addSelect('ID')
			->addFilter('IBLOCK_PROPERTY.CODE', 'UUID_1S_MULTI')
			->addFilter('IBLOCK_ELEMENT_ID', $productId)
			->exec()
			->fetch();
	}
}