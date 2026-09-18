<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use CIBlockElement;
use Logema\Utils\DataAccess\IblockHelper;
use Bitrix\Catalog\ProductTable;

class ProductBasicFlagUpdater
{
	/*
	 * Пересчитывает COMPLECT_BASIC_FILTER_FLAG для комплектов
	 */
	public static function updateAllProductBasicFlag(): void
	{
		set_time_limit(360);

		try {
			$select = [
				'ID',
				'ACTIVE',
				'PROPERTY_HIDE_IN_CATALOG',
				'PROPERTY_NO_ACTIVE_BY_SECTIONS',
				'PROPERTY_ACSESSUAR_SORT',
				'PROPERTY_ARCHIVE',
				'PROPERTY_PRICE_ON_REQUEST',
				'PROPERTY_COMPLECT_BASIC_FILTER_FLAG',
			];

			$filter = ['!PROPERTY_COMPLECT_ITEMS' => false];

			$complects = [];
			$chankSize = 1000;
			$lastId = 0;

			do {
				$filter['>ID'] = $lastId;
				$products = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementsByFilter($filter, $select, $chankSize, ['ID' => 'ASC']);
				$lastId = $products[array_key_last($products)]['ID'];

				foreach ($products as $product) {
					$complects[$product['ID']] = [
						'ACTIVE' => $product['ACTIVE'],
						'HIDE_IN_CATALOG' => $product['PROPERTY_HIDE_IN_CATALOG_VALUE'],
						'NO_ACTIVE_BY_SECTIONS' => $product['PROPERTY_NO_ACTIVE_BY_SECTIONS_VALUE'],
						'ACSESSUAR_SORT' => $product['PROPERTY_ACSESSUAR_SORT_VALUE'],
						'ARCHIVE' => $product['PROPERTY_ARCHIVE_VALUE'],
						'PRICE_ON_REQUEST' => $product['PROPERTY_PRICE_ON_REQUEST_VALUE'],
						'COMPLECT_BASIC_FILTER_FLAG' => $product['PROPERTY_COMPLECT_BASIC_FILTER_FLAG_VALUE']
					];
				}
			} while ($products);

			ComplectBasicPropertiesFlagComputer::updateComplectBasicFlag($complects);
		} catch (\Throwable $e) {
			return;
		}
	}
}