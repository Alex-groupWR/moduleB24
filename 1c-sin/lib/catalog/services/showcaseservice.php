<?php
namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Logema\Utils\DataAccess\HighloadblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogQueryParams;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogResult;
use Rusgeocom\Rusgeocom\Catalog\Entities\Showcase;
use Rusgeocom\Rusgeocom\Ui\Sort;
use Rusgeocom\Rusgeocom\Utils\HlBlockHelperRegistry;

class ShowcaseService
{
	/**
	 * @return Showcase[]
	 */
	public static function getAllShowcases(): array
	{
		$dbShowcases = static::getShowcaseHlbHelper()->getElementsByFilter([], ['*']);
		$showcases = [];
		foreach ($dbShowcases as $dbShowcase){
			$showcase = static::makeShowcase($dbShowcase);
			$showcases[$showcase->getName1C()] = $showcase;
		}

		return $showcases;
	}

	public static function getShowcaseByCode(int $cityId, string $code): ?Showcase
	{
		$filter = [
			'=UF_CITY' => $cityId,
			'=UF_LINK' => $code
		];
		$dbShowcase = static::getShowcaseHlbHelper()->getElementByFilter($filter, ['*']);

		return $dbShowcase ? static::makeShowcase($dbShowcase) : null;
	}

	/**
	 * @param int $showcaseId
	 * @return int[]
	 */
	public static function getAvailableProductIdsForShowcase(int $showcaseId): array
	{
		$productIds = [];
		$iterator = static::getShowcaseProductsHlbHelper()
			->getQuery()
			->addSelect('UF_PRODUCT_ID')
			->where('UF_STOCK', '>', 0)
			->where('UF_SHOWCASE', $showcaseId)
			->exec();
		while ($row = $iterator->fetch()){
			$productIds[] = (int)$row['UF_PRODUCT_ID'];
		}

		return $productIds;
	}

	private static function makeShowcase(array $dbShowcase): Showcase
	{
		$id = (int)$dbShowcase['ID'];
		$cityId = (int)$dbShowcase['UF_CITY'];
		$title = $dbShowcase['UF_H1'];
		$urlPart = $dbShowcase['UF_LINK'];
		$name1C = $dbShowcase['UF_1S_NAME'];

		return new Showcase($id, $cityId, $title, $urlPart, $name1C);
	}

	private static function getShowcaseHlbHelper(): HighloadblockHelper
	{
		return HlBlockHelperRegistry::getInstance()->getByCode('Showcases');
	}

	private static function getShowcaseProductsHlbHelper(): HighloadblockHelper
	{
		return HlBlockHelperRegistry::getInstance()->getByCode('ShowcaseProducts');
	}
}