<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Iblock\SectionElementTable;
use Bitrix\Iblock\SectionTable;
use Bitrix\Main\Entity\ExpressionField;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Iblock\Model\Section as BitrixSectionEntity;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Iblock\Elements\ElementCatalogTable;
use Bitrix\Main\ORM\Query\Query;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Brands\Brand;
use Rusgeocom\Rusgeocom\Catalog\Tables\CatalogSectionInfoTable;
use Rusgeocom\Rusgeocom\Types\Link;
use Rusgeocom\Rusgeocom\Types\NavChain;
use Rusgeocom\Rusgeocom\Ui\Localizer;
use Rusgeocom\Rusgeocom\Utils\Cache;

class Section
{
	const IMAGE_SIZE_SECTION_DESKTOP = [ 144 ];
	const IMAGE_SIZE_SECTION_TABLET = [ 80 ];
	const IMAGE_SIZE_SECTION_MOBILE = [ 72 ];
	const POPULAR_SECTION_COUNT_BASKET_PAGE = 4;
	const YOU_MAY_LIKE_SECTION_QUANTITY = 12;

	public static function isVerificationSection(int $sectionId): bool
	{
		return !!SectionTable::query()
			->addSelect('ID')
			->addFilter('ID', $sectionId)
			->addFilter('IBLOCK_SECTION_ID', VERIFICATION_SECTION_ID)
			->exec()
			->fetch();
	}

	public static function getRootBySection(int $sectionId, array $select = []): array
	{
		return \CIBlockSection::GetNavChain(CATALOG_IBLOCK_ID, $sectionId, $select)->Fetch();
	}

	private static function getByFilter(array $filter): ?Entities\Section
	{
		$select = ['ID', 'ACTIVE', 'NAME', 'DESCRIPTION', 'UF_*', 'DETAIL_PICTURE'];
		$dbSection = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getSectionByFilter($filter, $select);
		if (!$dbSection){
			return null;
		}

		return new Entities\Section($dbSection);
	}

	public static function getById(int $sectionId): ?Entities\Section
	{
		$filter = [
			'=ID' => $sectionId,
		];

		return static::getByFilter($filter);
	}

	public static function getActiveById(int $sectionId): ?Entities\Section
	{
		$filter = [
			'ACTIVE' => 'Y',
			'=ID' => $sectionId,
		];

		return static::getByFilter($filter);
	}

	public static function getSectionIdsByProductIds(array $productIds): array
	{
		$result = SectionElementTable::query()
			->addSelect('IBLOCK_SECTION_ID')
			->addFilter('=IBLOCK_ELEMENT_ID', $productIds)
			->exec()
			->fetchAll();

		$sectionIds = array_column($result, 'IBLOCK_SECTION_ID');

		return array_values(array_unique($sectionIds));
	}

	public static function getSectionsBrandsCount()
	{
		$iterator = CatalogSectionInfoTable::query()
			->addSelect('IBLOCK_SECTION_ID')
			->addSelect('BRANDS_COUNT_WITH_SUBSECTIONS')
			->exec();

		$sectionBrandCountMap = [];
		while ($sectionInfoItem = $iterator->fetch()) {
			$sectionBrandCountMap[$sectionInfoItem['IBLOCK_SECTION_ID']] = $sectionInfoItem['BRANDS_COUNT_WITH_SUBSECTIONS'];
		}

		return $sectionBrandCountMap;
	}

	public static function getSectionsForBrandPage(Brand $brand): array
	{
		$callback = static function () use ($brand) {
			return SectionElementTable::query()
				->addSelect('IBLOCK_SECTION_ID', 'SECTION_ID')
				->addSelect('SECTION.NAME', 'SECTION_NAME')
				->addSelect('ELEMENT.BRAND_REF.VALUE', 'SECTION_BRAND')
				->addSelect(new ExpressionField('TOTAL_IN_SECTION_COUNT', 'COUNT(*)'))
				->addSelect(new ExpressionField('ACCESSORIES_IN_SECTION_COUNT', 'COUNT(%s)',['ELEMENT.SECOND_SORT.VALUE']))
				->where('SECTION.ACTIVE', 'Y')
				->where('SECTION.GLOBAL_ACTIVE', 'Y')
				->where(
					Query::filter()
						->logic('or')
						->where('SECTION.UF_EXCLUDE_ON_BRAND_PAGE', 0)
						->whereNull('SECTION.UF_EXCLUDE_ON_BRAND_PAGE')
				)
				->where('ELEMENT.ACTIVE', 'Y')
				->where(
					Query::filter()
						->logic('or')
						->where('ELEMENT.BRAND_REF.VALUE', $brand->getUrl())
						->where('ELEMENT.BRAND_REF.VALUE', str_replace('-', '_', $brand->getUrl()))
						->whereLike('IBLOCK_SECTION.NAME', '%' . $brand->getName() . '%')
				)
				->whereNull('ELEMENT.ARCHIVE.VALUE')
				->registerRuntimeField(new ReferenceField(
						'ELEMENT',
						ElementCatalogTable::class,
						Join::on('this.IBLOCK_ELEMENT_ID', 'ref.ID')
					)
				)
				->registerRuntimeField(new ReferenceField(
						'SECTION',
						BitrixSectionEntity::compileEntityByIblock(CATALOG_IBLOCK_ID),
						Join::on('this.IBLOCK_SECTION_ID', 'ref.ID')
					)
				)
				->setGroup(['IBLOCK_SECTION_ID'])
				->setOrder(['SECTION.DEPTH_LEVEL' => 'DESC'])
				->fetchAll();
		};

		return Cache::create()
			->addTag('brandPageSections')
			->addKey($brand->getName())
			->setTime(3600)
			->setIblockId(CATALOG_IBLOCK_ID)
			->setCallback($callback)
			->getResult();
	}

	public static function makeNavChain(Localizer $localizer, int $sectionId, bool $showArchive = false): NavChain
	{
		$sections = SectionTree::getInstance()->getNavPath($sectionId);
		foreach ($sections as $index => $section){
			if ($index === 0) {
				continue;
			}

			$children = SectionTree::getInstance()->getChildren($section['ID']);
			foreach ($children as $child){
				if (!$child['SHOW_ON_MENU'] || ($child['ARCHIVE'] && !$showArchive)){
					continue;
				}

				$name = $localizer->localize($child['NAME'], $child['NAME_EN']);
				$sections[$index]['CHILDREN'][] = new Link($name, $child['URL'], $child['MANAGER_PREVIEW']);
			}
		}

		$navChain = new NavChain();
		foreach ($sections as $section){
			$name = $localizer->localize($section['NAME'], $section['NAME_EN']);
			$link = new Link($name, $section['URL'], $section['MANAGER_PREVIEW']);
			$navChain->addItem($link, $section['CHILDREN'] ?: []);
		}

		return $navChain;
	}
}
