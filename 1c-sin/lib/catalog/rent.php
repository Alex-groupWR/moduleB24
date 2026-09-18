<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Logema\Utils\DataAccess\IblockHelper;
use Bitrix\Iblock\Elements\ElementRentTable;

class Rent
{
	public static function calculateItem(int $itemId, int $days): int
	{
		if ($days <= 0){
			throw new \Exception('Не указан срок.');
		}

		$ranges = static::getRangesForItem($itemId);
		foreach ($ranges as $range){
			if ($range['MIN_DAYS'] <= $days && $range['MAX_DAYS'] >= $days){
				return $range['PRICE'] * $days;
			}
		}

		throw new \Exception('Не удалось рассчитать стоимость аренды.');
	}

	public static function getElementById(int $id, array $select): ?array
	{
		$rent = ElementRentTable::query()
			->setSelect($select)
			->where('ID', $id)
			->fetch();

		return $rent ?: null;
	}

	public static function getRangesForItems(array $itemIds): array
	{
		if (!$itemIds){
			return [];
		}

		$select = [
			'PROPERTY_SINCE',
			'PROPERTY_TILL',
			'PROPERTY_PRICEFILIAL',
			'CATALOG_PRICE_1',
			'PROPERTY_CML2_LINK'
		];
		$filter = [
			'PROPERTY_CML2_LINK' => $itemIds,
			'ACTIVE' => 'Y'
		];
		$offers = IblockHelper::forIblock(RENT_OFFERS_IBLOCK_ID)->getElementsByFilter($filter, $select);
		$itemsRanges = [];
		foreach ($offers as $offer){
			$itemsRanges[$offer['PROPERTIES']['CML2_LINK']['VALUE']][] = [
				'MIN_DAYS' => $offer['PROPERTIES']['SINCE']['VALUE'],
				'MAX_DAYS' => $offer['PROPERTIES']['TILL']['VALUE'] ?: 60,
				'PRICE' => intval($offer['PROPERTIES']['PRICEFILIAL']['VALUE'] ?: $offer['CATALOG_PRICE_1']),
			];
		}

		foreach ($itemsRanges as $itemId => $itemRanges){

			usort($itemRanges, function($left, $right){
				return ($left['MIN_DAYS'] - $right['MIN_DAYS']);
			});

			$itemsRanges[$itemId] = $itemRanges;
		}

		return $itemsRanges;
	}

	public static function getRangesForItem(int $itemId): array
	{
		if (!$itemId){
			throw new \Exception('Не указан ID элемента.');
		}

		return static::getRangesForItems([$itemId])[$itemId] ?: [];
	}
}