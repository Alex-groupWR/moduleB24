<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use CIBlockElement;
use Logema\Utils\DataAccess\IblockHelper;
use Bitrix\Catalog\ProductTable;

class ProductUpdater
{
	/*
	 * Инициирует обновления всех товаров во имя проставления у каждого товара '1' в поле "имеется в наличии"
	 */
	public static function updateAllProductAvailability(): void
	{
		set_time_limit(360);

		try {
			$select = [
				'ID',
				'PROPERTY_AVAILABLE_IN_STOCK',
				'PROPERTY_COMPLECT_MAIN_PRODUCT',
				'PROPERTY_COMPLECT_ITEMS',
				'PROPERTY_UUID_1S_MULTI',
			];

			$chankSize = 1000;
			$lastId = 0;

			do {
				$productsAvailable = [];
				$filter['>ID'] = $lastId;
				$products = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementsByFilter($filter, $select, $chankSize, ['ID' => 'ASC']);
				$lastId = $products[array_key_last($products)]['ID'];

				foreach ($products as $product) {
					$productsAvailable[$product['ID']] = [
						'available' => $product['PROPERTY_AVAILABLE_IN_STOCK_VALUE'],
						'isComplect' => !!$product['PROPERTY_COMPLECT_ITEMS_VALUE'],
						'complectItems' => $product['PROPERTIES']['COMPLECT_ITEMS']['VALUE'],
						'hasMultiUuId' => !!$product['PROPERTY_UUID_1S_MULTI_VALUE'],
						'multiUuids' => $product['PROPERTIES']['UUID_1S_MULTI']['VALUE'],
					];
				}

				ProductAvailabilityChecker::checkProductExist($productsAvailable);
			} while ($products);
		} catch (\Throwable $e) {
			return;
		}
	}
}