<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Entities\SetItem;
use Rusgeocom\Rusgeocom\Types\Image;
use Rusgeocom\Rusgeocom\Utils\HtmlParser;
use Rusgeocom\Rusgeocom\Utils\Url;

final class Sets
{
	public const string PROPERTY_COMPLECT_TEXT_CODE = 'COMPLECT_TEXT';
	public const string PROPERTY_SET_IDS_CODE = 'COMPLECT';

	public static function makeFromProductProperties(array $properties): Collection
	{
		$itemIds = self::getIdsFromString($properties[self::PROPERTY_SET_IDS_CODE]['VALUE'] ?? '');
		$html = trim($properties[self::PROPERTY_COMPLECT_TEXT_CODE]['~VALUE']['TEXT'] ?? '');

		return $itemIds
			? self::makeItemsFromIds($itemIds)
			: self::makeItemsFromHtml($html);
	}

	public static function getIdsFromString(string $idString): array
	{
		return Str::of($idString)
			->trim()
			->explode(',')
			->map(static fn(string $item) => (int)trim($item))
			->filter(static fn(int $id) => $id > 0)
			->toArray();
	}

	private static function makeItemsFromIds(array $ids): Collection
	{
		$items = Collection::empty();
		if (!$ids) {
			return $items;
		}

		$select = ['NAME', 'PREVIEW_PICTURE', 'PROPERTY_LINK', 'PROPERTY_COUNT'];
		$filter = [
			'ID' => $ids,
			'ACTIVE' => 'Y',
		];
		$sort = [
			'SORT' => 'ASC',
			'ID' => 'ASC'
		];
		$dbItems = self::getHelper()->getElementsByFilter($filter, $select, 0, $sort);
		foreach ($dbItems as $dbItem) {
			$count = (int)$dbItem['PROPERTIES']['COUNT']['VALUE'] ?: 1;

			$image = null;
			if ($dbItem['PREVIEW_PICTURE']) {
				$image = Image::fromIblockElement($dbItem['PREVIEW_PICTURE'], $dbItem['NAME']);
			}

			$items->add(
				new SetItem(
					htmlspecialchars_decode($dbItem['NAME']),
					$count,
					Url::formatSlash($dbItem['PROPERTIES']['LINK']['VALUE'] ?: ''),
					$image
				)
			);
		}

		return $items;
	}

	/**
	 * @return Collection<SetItem>
	 */
	public static function makeItemsFromHtml(string $html): Collection
	{
		$items = Collection::empty();
		if (!$html) {
			return $items;
		}

		$listItems = HtmlParser::getFirstListItems($html);
		foreach ($listItems as $listItem) {
			$items->add(new SetItem($listItem));
		}

		return $items;
	}

	private static function getHelper(): IblockHelper
	{
		return IblockHelper::forIblock(SETS_IBLOCK_ID);
	}
}
