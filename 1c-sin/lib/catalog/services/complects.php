<?php

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Core\Discounts\Enums\DiscountAmountView;
use Rusgeocom\Rusgeocom\Catalog\Availability;
use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\ComplectPriceCalculator;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductStocks;
use Rusgeocom\Rusgeocom\Catalog\Price;
use Rusgeocom\Rusgeocom\Types\Image;
use Rusgeocom\Rusgeocom\Utils\Url;

class Complects
{
	public const DEFAULT_PRODUCT_QUANTITY = 1;

	public static function getComplectsWithProducts(int $mainProductId): array
	{
		$complectsWithProducts = [];

		$filter = [
			'IBLOCK_ID' => CATALOG_IBLOCK_ID,
			'COMPLECT_BASIC_FILTER_FLAG' => 1,
			'PROPERTY_COMPLECT_MAIN_PRODUCT' => $mainProductId,
			'!PROPERTY_ARCHIVE_VALUE' => 'Да',
			'!PROPERTY_DONT_SHOW_COMPLECT_IN_COMPLECTS_VALUE' => 'Да',
			'ACTIVE' => 'Y'
		];

		$select = [
			'PROPERTY_COMPLECT_ITEMS',
		];

		$complects = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementsByFilter($filter, $select);

		foreach ($complects as $complect) {
			$complectsWithProducts[$complect['ID']] =
				array_map(function($value) {
					return stristr($value, '*', true) !== false ? stristr($value, '*', true) : $value;
				}, $complect['PROPERTIES']['COMPLECT_ITEMS']['VALUE'] ?? []);
		}

		return $complectsWithProducts;
	}

	/**
	 * @param int $complectMainProductId id основного товара комплекта
	 * @param int $productId id текущего элемента
	 * @return array
	 */
	public static function makeComplects($complectMainProductId, $productId): array
	{
		//заполнен только у комплекта
		if ($complectMainProductId) {
			$complectId = $productId;
			$productId = $complectMainProductId;
		}
		if (!$productId) {
			return [];
		}

		$productIds = [];
		$complectItemsIds = [];

		$complectsWithProducts = static::getComplectsWithProducts($productId);

		//убираем комплект из самого себя
		if ($complectId) {
			unset($complectsWithProducts[$complectId]);
		}

		foreach ($complectsWithProducts as $complectsWithProduct) {
			if ($complectsWithProduct) {
				$productIds = array_unique(array_merge($productIds,$complectsWithProduct));
			}
		}

		$availableComplectIds = array_keys(Availability::getInStockProductIds(array_keys($complectsWithProducts) ?: [], true));

		if (!in_array($productId, $availableComplectIds)) {
			array_unshift($availableComplectIds, $productId);
		}

		if (count($availableComplectIds) === 1) {
			$availableComplectIds = [];
		}

		$products = static::getProductsById($availableComplectIds);

		$productWithItems = static::makeFromComplectProducts($products);

		if (count($products) === 1) {
			$productWithItems = [];
		}

		return $productWithItems;
	}

	private static function makeFromComplectProducts(array $products): array
	{
		$complectItemsIds = [];
		foreach ($products as $product) {
			$values = static::getProductComplectItemIds($product);

			if (!$values) {
				continue;
			}

			foreach ($values as $value) {
				$complectItemsIds[] = $value;
			}
		}

		$productVariants = ComplectPriceCalculator::getProductVariants($complectItemsIds);

		foreach ($productVariants as $variantIds) {
			foreach ($variantIds as $variantId) {
				$complectItemsIds[] = $variantId;
			}
		}

		$complectItemsIds = array_unique($complectItemsIds);
		$prices = ComplectPriceCalculator::getPricesForProducts($complectItemsIds);

		return static::makeFullProductList(
			$products,
			static::getItems($complectItemsIds),
			$productVariants,
			$prices
		);
	}

	public static function makeComplectsByIds(array $complectIds): array
	{
		$products = Complects::getProductsById($complectIds);

		return Complects::makeFromComplectProducts($products);
	}

	public static function getIdAndQuantity($itemId): array
	{
		[$id, $quantity] = explode("*", $itemId);

		$quantity = floor($quantity);

		if (!$quantity || $quantity == 0 || gettype($quantity) == 'string') {
			$quantity = static::DEFAULT_PRODUCT_QUANTITY;
		}

		return [$id, $quantity];
	}

	public static function getQuantityComplectItems(array $complects): array
	{
		$quantityComplectItems = [];

		foreach ($complects as $complectId=>$complect) {
			foreach ($complect as $product) {
				[$productId, $quantity] = Complects::getIdAndQuantity($product);
				$quantityComplectItems[$complectId][$productId] = $quantity;
			}
		}

		return $quantityComplectItems;
	}

	public static function getItems(array $complectItemIds): array
	{
		$itemIds = [];
		foreach ($complectItemIds as $key => $itemId) {
			[$itemId] = static::getIdAndQuantity($itemId);
			$itemIds[$key] = $itemId;
		}

		if (!$itemIds) {
			return [];
		}

		$filter = [
			'IBLOCK_ID' => CATALOG_IBLOCK_ID,
			'COMPLECT_BASIC_FILTER_FLAG' => 1,
			'ID' => $itemIds,
		];
		$select = [
			'ID',
			'NAME',
			'DETAIL_PICTURE',
			BASE_PRICE_NAME,
			'PROPERTY_COMPLECT_DISCOUNT_SIZE',
			'PROPERTY_ALIASE',
			'PROPERTY_PRICE_OLD',
			'PROPERTY_ARTICUL',
		];

		return IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementsByFilter($filter, $select, 0);
	}

	public static function removeComplectIfItemNotActive($complects, $items):array
	{
		foreach ($complects as $key => $complect) {
			$complecttemIds = $complect['PROPERTIES']['COMPLECT_ITEMS']['VALUE'];
			$itemIds = [];

			foreach ($complecttemIds as $keyId => $itemId) {
				[$itemId] = static::getIdAndQuantity($itemId);

				$itemIds[$keyId] = $itemId;
			}

			foreach ($itemIds as $item) {
				if(!key_exists($item, $items)) {
					unset($complects[$key]);
				}
			}
		}

		return $complects;
	}

	public static function makeFullProductList(
		array $complects,
		array $items,
		array $productVariants,
		array $prices
	): array {
		$displayPercent = DiscountAmountView::Percent;
		$result = [];
		$productIds = [];

		$complects = static::removeComplectIfItemNotActive($complects, $items);

		foreach ($complects as $complectProduct) {
			$productIds[] = $complectProduct['ID'];
		}

		$catalogProducts = Catalog::getProductsByIds($productIds, withDetailPrices: true);
		$complectsCount = 1;

		foreach ($complects as $complectProduct) {
			$values = static::getProductComplectItemIds($complectProduct);

			if (!$values) {
				continue;
			}

			$productId = $complectProduct['ID'];
			$product = $catalogProducts->getById($productId);
			$complectPrice = $product->getPrice();

			if (!$complectPrice) {
				continue;
			}

			$result[$productId] = [
				'id' => $productId,
				'name' => $product->getComplectName() ?: 'Комплект ' . $complectsCount,
				'article' => $product->getArticul(),
				'offerId' => $product->getDefaultVariantId(),
				'url' => Url::makeAbsoluteUrl($product->getUrl()),
				'price' => $product->makePrices(false, true),
			];
			$result[$productId]['price']['currency'] = $product->getCurrency();
			//Для выгодных комплектов нет Первой скидки
			$hasFirstDiscount = $result[$productId]['price']['hasFirstDiscount'] ?? false;
			if ($hasFirstDiscount && $product->hasAuthorizationDiscountLabel()) {
				$result[$productId]['price']['hasFirstDiscount'] = false;
				$result[$productId]['price']['firstDiscountHint'] = null;
				$result[$productId]['price']['hasGreenPrice'] = $product->hasGreenPrice();
				$result[$productId]['price']['price'] = $product->getCurrentPrice();
			}

			$percentComplectDiscount = abs((float)$complectProduct['PROPERTY_COMPLECT_DISCOUNT_SIZE_VALUE']);
			$oldComplectPrice = 0;
			$complectItemsPrice = 0;

			foreach ($values as $value) {
				[$value, $quantity] = static::getIdAndQuantity($value);

				$oldPrice = (float)$items[$value]['PROPERTY_PRICE_OLD_VALUE'];
				$itemPrice = (float)$items[$value]['CATALOG_PRICE_1'];
				if (!$oldPrice) {
					$oldPrice = $itemPrice;
				}
				$itemCurrency = $items[$value]['CATALOG_CURRENCY_1'];

				if ($productVariants[$items[$value]['ID']]) {
					$itemVariantId = reset($productVariants[$items[$value]['ID']]);
					$oldPrice = $itemPrice = $prices[$itemVariantId]['PRICE'];
					$itemCurrency = $prices[$itemVariantId]['CURRENCY'];
				}

				$itemPrice = $itemOldPrice = Price::convertToBaseCurrency($itemPrice ?: 0, $itemCurrency ?: 'RUB');
				$oldPrice = Price::convertToBaseCurrency($oldPrice, $itemCurrency ?: 'RUB');
				if ($oldPrice > $itemOldPrice) {
					$itemOldPrice = $oldPrice;
				}

				$itemDiscountPercent = abs((float)$items[$value]['PROPERTY_COMPLECT_DISCOUNT_SIZE_VALUE']);
				$itemDiscount = $itemDiscountPercent;

				if ($itemDiscount) {
					$itemDiscount = $itemPrice / 100 * $itemDiscount;
				} else {
					$itemDiscount = $itemPrice / 100 * $percentComplectDiscount;
				}

				$itemPrice -= (integer)$itemDiscount;

				$totalPrice = $oldPrice * $quantity;
				$oldComplectPrice += $totalPrice;
				$complectItemsPrice += $itemPrice * $quantity;

				$result[$productId]['items'][] = [
					'id' => $items[$value]['ID'],
					'name' => $items[$value]['NAME'] . ($quantity > 1 ? ' (' . $quantity . ' шт.)' : ''),
					'article' => $items[$value]['PROPERTY_ARTICUL_VALUE'],
					'image' => Image::fromIblockElement(
						$items[$value]['DETAIL_PICTURE'],
						$items[$value]['NAME']
					)->resize([175, 175]),
					'price' => [
						'price' => $itemPrice,
						'oldPrice' => $itemOldPrice, // $itemOldPrice сейчас не особо то и актуален
						'totalPrice' => $totalPrice,
					],
					'quantity' => $quantity,
					'currentDiscount' => (int)$itemDiscountPercent ?: $percentComplectDiscount,
					'discountDisplay' => $displayPercent->value,
					'url' => Url::formatSlash($items[$value]['PROPERTY_ALIASE_VALUE']),
				];
			}

			$result[$productId]['discount'] = $oldComplectPrice - $complectPrice;

			if ($complectPrice === (int)$oldComplectPrice) {
				$result[$productId]['price']['oldPrice'] = null;
			} else {
				$result[$productId]['price']['oldPrice'] = $oldComplectPrice;
			}

			$difference = $complectItemsPrice - $complectPrice;
//			$itemsPrice = 0;
//			if ($difference) {
//				foreach ($result[$productId]['items'] as &$item) {
//					// Если у комплекта стоит галочка "не пересчитывать цену", то скорее всего различие в сумме товаров и цене комплекта
//					// будет большое, тогда актуализируем цену составляющих
//					if ($complectProduct['PROPERTY_DISABLE_CALC_PRICE_VALUE'] === 'Y') {
//						$percentage = $item['price']['price'] / $complectItemsPrice;
//						$item['price']['price'] = (int)($item['price']['price'] - $difference * $percentage);
//					}
//					$itemsPrice += $item['price']['price'];
//				}
//				unset($item);
//
//				$remains = $complectPrice - $itemsPrice;
//				$result[$productId]['items'][0]['price']['price'] += $remains;
//			}
			$complectsCount++;
		}

		return $result;
	}

	public static function getProductComplectItemIds(array $product): array
	{
		$result = [];

		if ($product['PROPERTIES']['COMPLECT_ITEMS']['VALUE']) {
			$result = array_merge($result, $product['PROPERTIES']['COMPLECT_ITEMS']['VALUE']);
		}

		return $result;
	}

	public static function getProductsById($complectsItemsIds): array
	{
		if ($complectsItemsIds) {
			$select = [
				'NAME',
				'DETAIL_PICTURE',
				'COMPLECT_MAIN_PRODUCT',
				'PROPERTY_ALIASE',
				'PROPERTY_COMPLECT_MAIN_PRODUCT',
				'PROPERTY_COMPLECT_ITEMS',
				BASE_PRICE_NAME,
				'PROPERTY_COMPLECT_DISCOUNT_SIZE',
				'PROPERTY_PRICE_MODIFIER',
				'PROPERTY_PRICE_ON_REQUEST',
				'PROPERTY_DISABLE_CALC_PRICE',
			];
			$filter = [
				'ID' => $complectsItemsIds,
				'ACTIVE' => 'Y',
				'!PROPERTY_PRICE_ON_REQUEST_VALUE' => 'Да'
			];
			$sort = [
				'SORT' => 'ASC',
			];
			return IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementsByFilter($filter, $select, 0, $sort);
		}

		return [];
	}

	public static function getOldPriceComplect(array $complectItems, int $price): int
	{
		$complectsItemsIds = [];
		foreach ($complectItems as $key => $itemId) {
			[$itemId] = static::getIdAndQuantity($itemId);

			$complectsItemsIds[$key] = $itemId;
		}

		$productVariants = ComplectPriceCalculator::getProductVariants($complectsItemsIds);
		foreach ($productVariants as $variantIds) {
			foreach ($variantIds as $variantId) {
				$complectsItemsIds[] = $variantId;
			}
		}

		$complectItemsIds = array_unique($complectsItemsIds);
		$prices = ComplectPriceCalculator::getPricesForProducts($complectItemsIds);

		$oldComplectPrice = 0;

		foreach ($complectItems as $item) {
			[$itemId, $quantity] = static::getIdAndQuantity($item);

			if ($productVariants[$itemId]) {
				$itemVariantId = reset($productVariants[$itemId]);
				$itemCurrency = $prices[$itemVariantId]['CURRENCY'];
				$itemPrice = $prices[$itemVariantId]['OLD_PRICE'] ?? $prices[$itemVariantId]['PRICE'];
			} else {
				$itemPrice = $prices[$itemId]['OLD_PRICE'] ?? $prices[$itemId]['PRICE'];
				$itemCurrency = $prices[$itemId]['CURRENCY'];
			}

			$itemPrice = Price::convertToBaseCurrency($itemPrice ?: 0, $itemCurrency ?: 'RUB');
			$oldComplectPrice += ($itemPrice * $quantity);
		}

		return $oldComplectPrice > $price ? $oldComplectPrice : 0;
	}

	public static function getQuantityComplectItemsInStocks(array $complectItems): array
	{
		return Availability::calculateStocksForProducts(array_column(static::getComplectItemsIdsAndQuantity($complectItems), 'id'));
	}

	public static function getComplectItemsIdsAndQuantity(array $complectItems): array
	{
		$complectItemsIdQuantity = [];

		foreach ($complectItems as $item) {
			[$id, $quantity] = static::getIdAndQuantity($item);
			$complectItemsIdQuantity[$id] = ['id' => $id, 'quantity' => $quantity];
		}

		return $complectItemsIdQuantity;
	}

	public static function checkAvailableComplect(array $complectItemsInStocks, array $complectItemsIdQuantity): bool
	{
		$maxItemsStock = [];

		/** @var ProductStocks $product */
		foreach ($complectItemsInStocks as $productId=>$product) {
			$maxItemsStock[$productId] = floor($product->getSumAmount() / $complectItemsIdQuantity[$productId]['quantity']);
		}

		return min($maxItemsStock) > 0;
	}

	public static function checkAvailableComplectWithoutDlrStore(array $complectItemsInStocks, array $complectItemsIdQuantity): bool
	{
		$maxItemsStock = [];

		/** @var ProductStocks $product */
		foreach ($complectItemsInStocks as $productId=>$product) {
			$maxItemsStock[$productId] = floor($product->getSumAmountWithoutDlrStore() / $complectItemsIdQuantity[$productId]['quantity']);
		}

		return min($maxItemsStock) > 0;
	}
}