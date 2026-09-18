<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\StoreProductTable;
use Bitrix\Main\EventManager;
use CIBlockElement;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Services\Complects;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;
use Rusgeocom\Rusgeocom\Stores\StoreService;

class ProductAvailabilityChecker
{
	public static $isProcessing = false;
	// По факту с "уникальными" комплектами (состоит сам из себя) мы здесь вообще ничего особенного не должны делать,
	// потому что это обычный товар, но случился прецедент, когда у товара не был заполненно свойство "УТ множ",
	// что является признаком уникального комплекта, но были заполнены "товары комплекта" и он обработался как комплект,
	// но не правильно, потому что такие случаи не обрабатывались
	// это *и**е*
	public static $isUniqueComplects = null; // В 1С есть редкие карточки комплектов, которые заведены как 1 карточка
	public static $isComplect = false;

	public static function bindEvents(): void
	{
		EventManager::getInstance()->addEventHandler('catalog', 'OnStoreProductUpdate', [__CLASS__, 'checkProductExists']);
	}

	public static function checkProductExists(int $intID, array $arFields): void
	{
		if (static::$isProcessing) return;
		$productId = $arFields['PRODUCT_ID'];
		$storeId = $arFields['STORE_ID'];

		$product = IblockHelper::forIblock(CATALOG_IBLOCK_ID)
			->getElementById(
				$productId,
				[
					'PROPERTY_COMPLECT_ITEMS',
					'PROPERTY_UUID_1S_MULTI',
					'PROPERTY_AVAILABLE_IN_STOCK'
				]
			);

		$isUniqueComplect = !!$product['PROPERTY_UUID_1S_MULTI_VALUE'];
		$hasProductComplectItems = !!$product['PROPERTIES']['COMPLECT_ITEMS']['VALUE'];

		if ($hasProductComplectItems && !$isUniqueComplect) {
			static::updateAvailablePropertyUniqueComplects([$product['ID']]);
		} else if (!$hasProductComplectItems) {
			$complects = static::getComplects($productId);

			static::recalculateQuantityForProduct($complects, $storeId);
			static::checkProductExist([$productId => ['available' => $product['PROPERTY_AVAILABLE_IN_STOCK_VALUE']]]);
		} else {
			static::$isComplect = true;
			$complect[$product['ID']] = $product['PROPERTIES']['COMPLECT_ITEMS']['VALUE'];

			$itemUtCodes = $product['PROPERTIES']['UUID_1S_MULTI']['VALUE'];
			$existingItems = static::getProductsByUtCodes($itemUtCodes);
			if (count($existingItems) !== count($itemUtCodes)) {
				static::updateAvailableProperty([$product['ID'] => 0]);
				return;
			}

			static::recalculateQuantityForProduct($complect, $storeId);
		}
	}

	private static function getProductAndComplectAndUniqueComplectIds(array $productsAvailable): array
	{
		$complectIds = [];
		$simpleProductIds = [];
		$uniqueComplectIds = [];

		foreach ($productsAvailable as $productId=>$product) {
			if ($product['isComplect'] && !$product['hasMultiUuId']) {
				$uniqueComplectIds[] = $productId;
			} else if ($product['isComplect']) {
				$complectIds[] = $productId;
			} else {
				$simpleProductIds[] = $productId;
			}
		}
		return [$complectIds, $simpleProductIds, $uniqueComplectIds];
	}

	private static function makeComplects(array $productsAvailable, array $complectIds): array
	{
		$complects = [];

		foreach ($complectIds as $complectId) {
			$complects[$complectId] = $productsAvailable[$complectId]['complectItems'];
		}

		return $complects;
	}

	public static function checkProductExist(array $productsAvailable) {
		[$complectIds, $simpleProductIds, $uniqueComplectIds] = static::getProductAndComplectAndUniqueComplectIds($productsAvailable);
		$complects = static::makeComplects($productsAvailable, $complectIds);
		// раз в сортировке с отсутствующими кодами не в наличии - то делаем и тут
		$incorrectComplects = static::getComplectsWithIncorrectCodes($productsAvailable, $complects);
		if ($incorrectComplects) {
			static::updateAvailableProperty($incorrectComplects);
			$incorrectComplectIds = array_keys($incorrectComplects);
			foreach ($incorrectComplectIds as $complectId) {
				unset($complects[$complectId]);
			}
		}

		static::updateAvailablePropertyUniqueComplects($uniqueComplectIds);
		static::updateComplects($complects);
		static::updateProductFlagAvailable($simpleProductIds, $productsAvailable);
	}

	private static function getComplectsWithIncorrectCodes(array $productsAvailable, array $complects): array
	{
		$incorrectComplects = [];

		$utCodesForMap = [];
		foreach ($complects as $complectId => $complect) {
			$utCodesForMap = array_merge($utCodesForMap, $productsAvailable[$complectId]['multiUuids']);
		}

		$utMap = static::getProductsByUtCodes($utCodesForMap);
		$allCodes = array_keys($utMap);
		foreach ($complects as $complectId => $complect) {
			$complectProduct = $productsAvailable[$complectId];
			if (array_diff($complectProduct['multiUuids'], $allCodes)) {
				$incorrectComplects[$complectId] = 0;
			}
		}

		return $incorrectComplects;
	}

	private static function getProductsByUtCodes(array $productCodes): array
	{
		$products = [];

		if (!$productCodes) {
			return [];
		}

		$iterator = IblockElementPropertyTable::query()
			->addSelect('VALUE')
			->addSelect('IBLOCK_ELEMENT_ID')
			->where('IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->where('IBLOCK_PROPERTY.CODE', 'UT_GUID_NEW')
			->whereIn('VALUE', $productCodes)
			->exec();
		while ($row = $iterator->fetch()) {
			$products[$row['VALUE']][] = $row['IBLOCK_ELEMENT_ID'];
		}

		return $products;
	}

	public static function recalculateQuantityForProduct(array $complects, int $storeId): void
	{
		if (static::$isProcessing) return;
		static::$isProcessing = true;

		static::updateComplectFlagAvailable($complects);

		static::updateStocks(static::getComplectsNeedUpdateAmountOnStock($complects, $storeId));

		static::$isProcessing = false;
	}

	private static function getComplects(int $productId): array
	{
		if (!$productId){
			return [];
		}

		$newProductIds[] = $productId;
		$newProductIds[] = $productId.'*%';

		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addFilter('=IBLOCK_PROPERTY.CODE', 'COMPLECT_ITEMS')
			->addFilter('=IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addFilter('VALUE', $newProductIds)
			->exec();
		while ($row = $iterator->fetch()){
			$allItemIds[] = $row['IBLOCK_ELEMENT_ID'];
		}

		$complects = [];
		$isUniqueComplects = [];
		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addSelect('IBLOCK_PROPERTY.CODE', 'PROPERTY_CODE')
			->addFilter('=IBLOCK_ELEMENT_ID', $allItemIds)
			->addFilter('=IBLOCK_PROPERTY.CODE', ['COMPLECT_ITEMS', 'UUID_1S_MULTI'])
			->addFilter('=IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addFilter('!=VALUE', false)
			->exec();

		while ($row = $iterator->fetch()) {
			$complectId = $row['IBLOCK_ELEMENT_ID'];
			$propertyValue = $row['VALUE'];

			if ($row['PROPERTY_CODE'] === 'COMPLECT_ITEMS' && !in_array($propertyValue, $complects[$complectId] ?? [])) {
				$complects[$complectId][] = $propertyValue;
			}

			if ($row['PROPERTY_CODE'] === 'UUID_1S_MULTI') {
				$isUniqueComplects[$complectId] = 0;
			} else {
				if (!in_array($complectId, $isUniqueComplects)) {
					$isUniqueComplects[$complectId] = 1;
				}
			}
		}

		foreach ($isUniqueComplects as $complectId=>$isUnique) {
			if ($isUnique) {
				unset($complects[$complectId]);
			}
		}

		return $complects;
	}

	private static function getComplectsNeedUpdateAmountOnStock(array $complects, int $storeId): array
	{
		$output = [];

		$complects = Complects::getQuantityComplectItems($complects);

		foreach ($complects as $complectId=>$complect) {
			$output[$storeId][$complectId] = static::getLeastAmountItems($complect, $storeId);
		}

		return $output;
	}

	private static function getLeastAmountItems(array $complect, int $storeId): int
	{
		$result = [];
		$itemsAmount = [];
		$productIds = array_keys($complect);
		$iterator = StoreProductTable::query()
			->addSelect('PRODUCT_ID')
			->addSelect('AMOUNT')
			->addFilter('=STORE_ID', $storeId)
			->addFilter('=PRODUCT_ID', $productIds)
			->exec();
		while ($row = $iterator->fetch()){
			$itemsAmount[$row['PRODUCT_ID']] = $row['AMOUNT'] > 0 ? $row['AMOUNT'] : 0;
		}

		if (empty($itemsAmount)){
			return 0;
		}

		foreach ($itemsAmount as $productId=>$quantity) {
			$complectItemQuantity = $complect[$productId] >= 2 ? floor($complect[$productId]) : 1;
			$result[] = floor($quantity / $complectItemQuantity);
		}

		return min($result);
	}

	private static function getAllComplectItemIds(array $complectItemQuantity): array
	{
		$productIds = [];

		foreach ($complectItemQuantity as $complect) {
			foreach (array_keys($complect) as $productId) {
				$productIds[] = $productId;
			}
		}

		return $productIds;
	}

	private static function getTotalProductsAmount(array $productIds, array $allStoreIds): array
	{
		$output = [];

		$iterator = StoreProductTable::query()
			->addSelect('AMOUNT')
			->addSelect('PRODUCT_ID')
			->addFilter('=PRODUCT_ID', $productIds)
			->addFilter('=STORE_ID', $allStoreIds)
			->exec();
		while ($row = $iterator->fetch()){
			$output[$row['PRODUCT_ID']] += $row['AMOUNT'] > 0 ? $row['AMOUNT'] : 0;
		}

		return $output;
	}

	private static function getAllProductsAmount(array $productIds, array $allStoreIds): array
	{
		$output = [];

		$iterator = StoreProductTable::query()
			->addSelect('AMOUNT')
			->addSelect('PRODUCT_ID')
			->addSelect('STORE_ID')
			->addFilter('=PRODUCT_ID', $productIds)
			->addFilter('=STORE_ID', $allStoreIds)
			->exec();
		while ($row = $iterator->fetch()){
			$output[$row['STORE_ID']][$row['PRODUCT_ID']] = $row['AMOUNT'] > 0 ? $row['AMOUNT'] : 0;
		}

		return $output;
	}

	/**
	 * Есть/нет товар на складах
	 *
	 * Для товара комплекта вычисляется в заависимости от количества необходимыз товаров для комплекта, например, 4
	 * батарейки, для обычных товаров проще: > 0 ? 1 : 0
	 *
	 * @return bool[]
	 */
	private static function checkProductsAvailable(array $itemsAmount, array $complectItemQuantity = []): array
	{
		$output = [];

		if ($complectItemQuantity) {
			foreach ($itemsAmount as $productId => $quantity) {
				$complectItemQuantity = $complectItemQuantity[$productId] >= 2 ? floor($complectItemQuantity[$productId]) : 1;
				$output[$productId] = floor($quantity / $complectItemQuantity) >= 1 ? 1 : 0;
			}
		} else {
			foreach ($itemsAmount as $productId => $quantity) {
				$output[$productId] = $quantity > 0 ? 1 : 0;
			}
		}

		return $output;
	}

	private static function setComplectProductAvailable(array $complectItemQuantity, array $productAvailable): array
	{
		$complects = [];

		foreach ($complectItemQuantity as $complectId=>$complect) {
			foreach ($complect as $productId=>$product) {
				$complects[$complectId][$productId] = '';
			}
		}

		foreach ($complects as $complectId=>$complect) {
			foreach ($complect as $productId=>$product) {
				$complects[$complectId][$productId] = $productAvailable[$productId];
			}
		}

		return $complects;
	}

	private static function getCurrentProductsAvailable(array $productIds): array
	{
		$output = [];

		$iterator = IblockElementPropertyTable::query()
			->addFilter('=IBLOCK_PROPERTY.CODE', 'AVAILABLE_IN_STOCK')
			->addFilter('=IBLOCK_ELEMENT_ID', $productIds)
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->exec();
		while ($row = $iterator->fetch()) {
			$output[$row['IBLOCK_ELEMENT_ID']] = $row['VALUE'] ? 1 : 0;
		}

		return $output;
	}

	private static function getComplectsForUpdate(array $complectProductsAvailable): array
	{
		$complectsAvailable = static::getCurrentProductsAvailable(array_keys($complectProductsAvailable));
		$output = [];

		foreach ($complectProductsAvailable as $complectId=>$complect) {
			if (!in_array(0, array_values($complect)) != $complectsAvailable[$complectId]) {
				$output[$complectId] = !in_array(0, array_values($complect)) ? 1 : 0;
			}
		}

		return $output;
	}

	private static function updateProductFlagAvailable(array $simpleProductIds, array $productsAvailable): void
	{
		$isAvailableProducts = [];
		$stores = StoreProductTable::query()
			->addSelect('PRODUCT_ID')
			->addSelect('STORE_ID')
			->addSelect('AMOUNT')
			->addSelect('ID')
			->addFilter('=PRODUCT_ID', $simpleProductIds)
			->exec();
		while ($row = $stores->fetch()) {
			// Отрицательное количество у поставщика считаем в наличии
			// Отрицательное количество на любом складе считаем в наличии, но не даём отплатить картой
			if (!key_exists($row['PRODUCT_ID'], $isAvailableProducts)) {
				if ($row['AMOUNT'] > 0 || ($row['STORE_ID'] == DLR_STORE_ID && $row['AMOUNT'] != 0)) {
					$isAvailableProducts[$row['PRODUCT_ID']] = 1;
				} elseif ($row['AMOUNT'] < 0 && $row['STORE_ID'] != DLR_STORE_ID ) {
					$isAvailableProducts[$row['PRODUCT_ID']] = -1;
				} elseif ($row['STORE_ID'] != DLR_STORE_ID && $row['AMOUNT'] == 0) {
					$isAvailableProducts[$row['PRODUCT_ID']] = 0;
				}
			} elseif (
				$isAvailableProducts[$row['PRODUCT_ID']] == 0
				&& ($row['AMOUNT'] > 0 || ($row['STORE_ID'] == DLR_STORE_ID && $row['AMOUNT'] != 0))
			) {
				$isAvailableProducts[$row['PRODUCT_ID']] = 1;
			} elseif ($isAvailableProducts[$row['PRODUCT_ID']] != 1 && $row['AMOUNT'] < 0 && $row['STORE_ID'] != DLR_STORE_ID) {
				$isAvailableProducts[$row['PRODUCT_ID']] = -1;
			}
		}

		foreach ($isAvailableProducts as $productId=>$isAvailable) {
			if ($productsAvailable[$productId]['available'] != $isAvailable) {
				// TODO: сделать через updateAvailableProperty
				CIBlockElement::SetPropertyValuesEx($productId, CATALOG_IBLOCK_ID, ['AVAILABLE_IN_STOCK' => $isAvailable]);
			}
		}
	}

	private static function getComplectWithoutUnique(array $complects): array
	{
		foreach ($complects as $complectId=>$complect) {
			if (in_array($complectId, static::$isUniqueComplects ?? [])) {
				unset($complects[$complectId]);
			}
		}

		return $complects;
	}

	private static function getProductsForUpdate(array $checkProductsAvailable, array $productsAvailable): array
	{
		$output = [];

		foreach ($checkProductsAvailable as $productId=>$available) {
			if ($productsAvailable[$productId] !== $available) {
				$output[$productId] = $available;
			}
		}

		return $output;
	}

	private static function updateAvailableProperty(array $products): void
	{
		foreach ($products as $productId=>$value) {
			CIBlockElement::SetPropertyValuesEx($productId, CATALOG_IBLOCK_ID, ['AVAILABLE_IN_STOCK' => $value]);
		}
	}

	private static function updateAvailablePropertyUniqueComplects(array $productIds): void
	{
		$allStoreIds = StoreService::getInstance()->getIds();
		$itemsAmount = static::getTotalProductsAmount($productIds, $allStoreIds);
		$newProductsAvailable = static::checkProductsAvailable($itemsAmount);
		$productsAvailable = static::getCurrentProductsAvailable($productIds);
		$productsForUpdate = static::getProductsForUpdate($newProductsAvailable, $productsAvailable);

		static::updateAvailableProperty($productsForUpdate);
	}

	private static function calculateTotalItemsAmount(array $itemsAmount): array
	{
		$output = [];

		foreach ($itemsAmount as $items) {
			foreach ($items as $itemId=>$quantity) {
				$output[$itemId] += $quantity;
			}
		}

		return $output;
	}

	private static function updateComplects(array $complects): void
	{
		$complects = static::getComplectWithoutUnique($complects);
		$complectItemQuantity = Complects::getQuantityComplectItems($complects);
		$productIds = static::getAllComplectItemIds($complectItemQuantity);
		$allStoreIds = StoreService::getInstance()->getIds();
		$itemsAmount = static::getAllProductsAmount($productIds, $allStoreIds);
		$totalItemsAmount = static::calculateTotalItemsAmount($itemsAmount);
		$productAvailable = static::checkProductsAvailable($totalItemsAmount, $complectItemQuantity);
		$complectProductsAvailable = static::setComplectProductAvailable($complectItemQuantity, $productAvailable);
		$complectsForUpdate = static::getComplectsForUpdate($complectProductsAvailable);

		// TODO: если идти через второе ветвление функции checkProductExists, то следующая функция выполнится 2 раза (не кул)
		static::updateAvailableProperty($complectsForUpdate);
		static::updateQuantity($itemsAmount, $complectItemQuantity);
	}

	private static function updateQuantity(array $itemsStoreAmount, array $complectItemNeedQuantity): void
	{
		static::$isProcessing = true;
		$productForUpdate = [];

		foreach ($itemsStoreAmount as $storeId=>$items) {
			foreach ($complectItemNeedQuantity as $complectId=>$complectItems) {
				$availableAmountComplectItems = [];

				foreach ($complectItems as $complectItemId=>$item) {
					$needQuantityForComplect = $item >= 2 ? floor($item) : 1;
					$availableAmountComplectItems[] = floor($items[$complectItemId] / $needQuantityForComplect);
				}

				$productForUpdate[$complectId][$storeId] = min($availableAmountComplectItems);
			}
		}

		$productIds = array_keys($productForUpdate);
		$storeIds = array_keys($itemsStoreAmount);

		$currentProductAmount = static::getAllProductsAmount($productIds, $storeIds);

		$productStoreList = static::getProductStoreList($storeIds, $productIds);

		foreach ($productForUpdate as $productId=>$store) {
			foreach ($store as $storeId=>$amount) {
				$productStoreId = $productStoreList[$productId][$storeId]['ID'];
				if ($productStoreId && $currentProductAmount[$storeId][$productId] != $amount) {
					static::updateStock($productId, $storeId, $amount, $productStoreId);
				}
			}
		}

		static::$isProcessing = false;
	}

 	private static function updateComplectFlagAvailable(array $complects): void
	{
		$complects = static::getComplectWithoutUnique($complects);
		$complectItemQuantity = Complects::getQuantityComplectItems($complects);
		$productIds = static::getAllComplectItemIds($complectItemQuantity);
		$allStoreIds = StoreService::getInstance()->getIds();
		$itemsAmount = static::getTotalProductsAmount($productIds, $allStoreIds);
		$productAvailable = static::checkProductsAvailable($itemsAmount, $complectItemQuantity);
		$complectProductsAvailable = static::setComplectProductAvailable($complectItemQuantity, $productAvailable);
		$complectsForUpdate = static::getComplectsForUpdate($complectProductsAvailable);

		static::updateAvailableProperty($complectsForUpdate);
	}

	private static function updateStocks(array $productQuantityForUpdate): void
	{
		$listNeedStores = [];
		$listNeedProducts = [];

		foreach ($productQuantityForUpdate as $storeId=>$store) {
			$listNeedStores[] = $storeId;
			foreach ($store as $productId => $amount) {
				$listNeedProducts[] = $productId;
			}
		}

		$productStoreList = static::getProductStoreList($listNeedStores, $listNeedProducts);

		foreach ($productQuantityForUpdate as $storeId=>$store) {
			foreach ($store as $productId=>$amount) {
				if ($productStoreList[$productId]['AMOUNT'] != $amount && $productStoreList[$productId][$storeId]['ID']) {
					static::updateStock($productId, $storeId, $amount, $productStoreList[$productId][$storeId]['ID']);
				}
			}
		}
	}

	private static function getProductStoreList(array $listNeedStores, array $listNeedProducts): array
	{
		$output = [];

		$stores = StoreProductTable::query()
			->addSelect('ID')
			->addSelect('AMOUNT')
			->addSelect('PRODUCT_ID')
			->addSelect('STORE_ID')
			->addFilter('=PRODUCT_ID', $listNeedProducts)
			->addFilter('=STORE_ID', $listNeedStores)
			->exec();
		while ($store = $stores->fetch()) {
			$output[$store['PRODUCT_ID']][$store['STORE_ID']] = $store;
		}

		return $output;
	}

	private static function updateStock(int $productId, int $storeId, int $amount, string $productStoreId): void
	{
		$arFields = [
			'PRODUCT_ID' => $productId,
			'STORE_ID' => $storeId,
			'AMOUNT' => $amount,
		];

		\CCatalogStoreProduct::Update($productStoreId, $arFields);
	}
}