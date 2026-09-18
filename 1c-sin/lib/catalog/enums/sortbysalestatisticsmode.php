<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Enums;

enum SortBySaleStatisticsMode: string
{
	case No = 'no'; // Нет
	case Yes = 'yes'; // Да
	case AllStores = 'all_stores'; // В наличии хотя бы на одном складе
	case WithoutSales = 'without_sales'; // Без учёта продаж
	case WithoutSalesPriceSort = 'without_sales_price_sort'; // Без учета продаж, сортировка по цене
	case WithoutSalesPriceSortRgkAmo = 'without_sales_price_sort_rgk_amo'; // Без продаж - кип - Акция/RGK/AMO по цене

	public static function fromValueId(int $valueId): ?self
	{
		return match ($valueId) {
			75 => self::Yes,
			76 => self::AllStores,
			81 => self::WithoutSales,
			89 => self::WithoutSalesPriceSort,
			101 => self::WithoutSalesPriceSortRgkAmo,
			default => self::No,
		};
	}
}