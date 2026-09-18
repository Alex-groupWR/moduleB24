<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Utils\Cache;
use Rusgeocom\Rusgeocom\Utils\Menu;
use Rusgeocom\Rusgeocom\Utils\Url;
use Rusgeocom\Rusgeocom\Utils\User;
use Closure;

class SectionTree
{
	private $sections = [];
	private $tree = [];
	private static $instance;

	public static function getInstance(): SectionTree
	{
		if (!static::$instance){
			static::$instance = new static();
		}

		return static::$instance;
	}

	private function __construct()
	{
		$sections = $this->fetchSectionsCached();
		foreach ($sections as $section){
			$isActive = $section['ACTIVE'] === 'Y' || ($section['UF_SHOW_FOR_MANAGERS_ONLY'] && User::hasManagerOnlyProductsAccess());
			$showOnMainMenu = (int)$section['UF_MAIN_MENU_SORT']
				|| ($section['UF_SHOW_ON_MENU'] && $section['UF_MAIN_MENU_SORT'] === null);
			$this->sections[$section['ID']] = [
				'ID' => $section['ID'],
				'NAME' => $section['NAME'],
				'NAME_EN' => $section['UF_NAME_EN'],
				'SORT' => $section['SORT'],

				// По этому флагу можно определять нужно ли выводить раздел.
				// Для обычных юзеров будут только реально активные, а для менеджеров ещё и предпросмотр.
				'ACTIVE' => $isActive,
				'CODE' => $section['CODE'],
				'URL' => Url::formatSlash($section['UF_ALIASE']),
				'NO_INDEX_MENU' => !!$section['UF_NO_INDEX_MENU'],
				'PARENT_ID' => $section['IBLOCK_SECTION_ID'],
				'ARCHIVE' => !!$section['UF_ARCHIVE_ITEMS_ONLY'],
				'SHOW_ON_MENU' => $section['UF_SHOW_ON_MENU'] && $section['UF_TMPL'] != 14 && $isActive,
				'SHOW_ON_MAIN_MENU' => $showOnMainMenu && $isActive,
				'SHOW_FILTER' => $section['UF_SHOW_FILTER'] == SECTION_UF_SHOW_FILTER_YES,
				'SHOW_ACESSORY' => !$section['UF_NOT_SHOW_ACESSORY'],
				'POPULAR_SECTION' => $section['UF_POPULAR_CATEGORY'],
				'PICTURE' => $section['PICTURE'],
				'BANNER_DESKTOP' => $section['UF_BANNER'] ?: null,
				'BANNER_TABLET' => $section['UF_BANNER_TABLET'] ?: null,
				'BANNER_LINK' => $section['UF_BANNER_LINK'] ?: '',
				'DEPTH_LEVEL' => $section['DEPTH_LEVEL'] ?: '',
				'PRODUCT_COUNT' => $section['UF_PRODUCT_COUNT'] ?: 0,
				'H1' => $section['UF_H1'] ?: '',
				'IS_SHOW_TO_WHOLESALERS' => $section['UF_DONT_SHOW_TO_WHOLESALERS'] !== "1",
				'MAIN_MENU_SORT' => $section['UF_MAIN_MENU_SORT'] ?? null,
				'MAX_SUBSECTION_QUANTITY' => $section['UF_MAX_QUANTITY_OF_VISIBLE_SUBSECTIONS'],
				'NAME_IN_CATALOG_MENU' => $section['UF_CATALOG_MENU_NAME'],

				// Это маркер, по которому в меню/крошках/везде раздел подсвечивается другим цветом (предпросмотр неактивных разделов)
				'MANAGER_PREVIEW' => $section['ACTIVE'] !== 'Y' && $section['UF_SHOW_FOR_MANAGERS_ONLY'] && User::hasManagerOnlyProductsAccess(),
			];
		}

		$this->tree = static::makeSectionTree($this->sections);
	}

	private function fetchSectionsCached(): array
	{
		$callback = function() {
			$select = [
				'IBLOCK_SECTION_ID',
				'NAME',
				'CODE',
				'SORT',
				'UF_ALIASE',
				'PICTURE',
				'UF_SHOW_ON_MENU',
				'UF_NAME_EN',
				'DEPTH_LEVEL',
				'UF_NO_INDEX_MENU',
				'UF_TMPL',
				'UF_ARCHIVE_ITEMS_ONLY',
				'UF_SHOW_FOR_MANAGERS_ONLY',
				'UF_SHOW_FILTER',
				'UF_NOT_SHOW_ACESSORY',
				'ACTIVE',
				'UF_POPULAR_CATEGORY',
				'UF_BANNER',
				'UF_BANNER_TABLET',
				'UF_BANNER_LINK',
				'UF_H1',
				'UF_DONT_SHOW_TO_WHOLESALERS',
				'UF_MAX_QUANTITY_OF_VISIBLE_SUBSECTIONS',
				'UF_CATALOG_MENU_NAME',
				'UF_MAIN_MENU_SORT'
			];
			$filter = [
				'IBLOCK_ID' => CATALOG_IBLOCK_ID,
				'!UF_ALIASE' => false,
				'!ID' => Menu::getDefaultExcludeSectionIds(),
			];
			$sort = ["SORT" => 'ASC'];

			return IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getSectionsByFilter($filter, $select, 0, $sort);
		};

		return Cache::create()
			->setCallback($callback)
			->addTag('sections')
			->addKey('sections')
			->setIblockId(CATALOG_IBLOCK_ID)
			->getResult();
	}

	public function getFilteredSectionsProperties(array $select, array $filter): array
	{
		$sort = ["SORT" => 'ASC'];
		return IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getSectionsByFilter($filter, $select, 0, $sort);
	}

	public function getChildren(int $sectionId): array
	{
		$sections = [];
		foreach ($this->sections as $section){
			if ($section['PARENT_ID'] == $sectionId){
				$sections[$section['ID']] = $section;
			}
		}

		return $sections;
	}

	public function getTree(int $rootSectionId = 0): array
	{
		if (!$rootSectionId){
			return $this->tree;
		}

		$navPath = $this->getNavPath($rootSectionId);
		$tree = $this->tree;
		foreach ($navPath as $section){
			$tree = $tree[$section['ID']]['CHILDREN'];
		}

		$section['CHILDREN'] = $tree;

		return $section;
	}

	public function getNavPath(int $sectionId): array
	{
		$result = [];

		$section = $this->getById($sectionId);
		while ($section && $sectionId) {
			$section = $this->getById($sectionId);
			if ($section) {
				$result[] = $section;
			}
			$sectionId = $section['PARENT_ID'];
		}

		return array_reverse($result);
	}

	public function getById(int $sectionId): array
	{
		return $this->sections[$sectionId] ?: [];
	}

	public function getByIds(array $sectionIds): array
	{
		$sections = [];
		foreach ($sectionIds as $sectionId) {
			$sections[] = $this->getById($sectionId);
		}
		return $sections;
	}

	public function getPlainList(): array
	{
		return $this->sections;
	}

	/**
	 * @return int[]
	 */
	public function getAllIds(): array
	{
		return array_keys($this->sections);
	}

	public function getPopularSections(): array
	{
		$popularSections = array_filter($this->sections, function($section){
			return !is_null($section['POPULAR_SECTION']) && $section['SHOW_ON_MENU'] && $section['ACTIVE'];
		});

        usort($popularSections, static function ($a, $b) {
            return $a['POPULAR_SECTION'] <=> $b['POPULAR_SECTION'];
        });

        return $popularSections;
	}

	public function getRootId(int $sectionId): int
	{
		return $this->getNavPath($sectionId)[0]['ID'] ?: 0;
	}

	public function getSectionUrlByLevel(int $level, int $sectionId): string
	{
		return $this->getNavPath($sectionId)[--$level]['URL'] ?: '';
	}

	/**
	 * @param null|Closure(array): bool $customFilter
	 * @return array
	 */
	public function getRootSectionsTree(?Closure $customFilter = null): array
	{
		$sections = array_filter(
			$this->sections,
			$customFilter ??
			function($section){
				return $section['DEPTH_LEVEL'] <= 3;
			}
		);

		return static::makeSectionTree($sections);
	}

	public function getParentId(int $sectionId): int
	{
		return (int)$this->getById($sectionId)['PARENT_ID'];
	}

	private static function makeSectionTree($sections): array
	{
		$sectionsByLevels = [];
		foreach ($sections as $section){
			$sectionsByLevels[$section['DEPTH_LEVEL'] ?: 0][$section['ID']] = $sections[$section['ID']];
		}

		ksort($sectionsByLevels);

		$sectionsByLevels = array_reverse($sectionsByLevels);
		$levelCount = count($sectionsByLevels);

		for ($i = 0; $i < $levelCount; $i++){

			if ($i){
				unset($sectionsByLevels[$i - 1]);
			}

			foreach ($sectionsByLevels[$i] as $section){
				if ($sectionsByLevels[$i + 1][$section['PARENT_ID']]){
					$sectionsByLevels[$i + 1][$section['PARENT_ID']]['CHILDREN'][$section['ID']] = $section;
				}
			}
		}

		return $sectionsByLevels[$levelCount - 1];
	}

	public static function clearCache(): void
	{
		Cache::clearByTag('sections');
		Cache::cleanDirectory('sections');
	}

	public function getSubsectionsIds(int $sectionId): array
	{
		$ids = [];

		$children = $this->getChildren($sectionId);
		foreach ($children as $child){
			$ids[] = (int)$child['ID'];
			$subsections = $this->getSubsectionsIds((int)$child['ID']);
			if ($subsections) {
				$ids = array_merge($ids, $subsections);
			}
		}

		return $ids;
	}
}
