<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use CIBlockElement;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogQueryParams;

abstract class CatalogProcessorBase
{
	protected int $totalCount = 0;

	/**
	 * @var list<int>
	 */
	protected array $allProductIds = [];

	protected function __construct(
		protected readonly CatalogQueryParams $params
	) {
	}

	public function getTotalCount(): int
	{
		$filter = $this->getFilter();
		return (!isset($filter['ID']) || empty($filter['ID'])) ? 0 : CIBlockElement::GetList([], $filter, [], false, ['ID']);
	}

	/**
	 * @return list<int>
	 */
	public function getProductIds(): array
	{
		$select = ['ID'];
		$filter = $this->getFilter();

		$sort = $this->getSort();
		$nav = false;

		if (!$this->params->isShowOutOfStock()) {
			$filter['PROPERTY_AVAILABLE_IN_STOCK'] = '1';
		}

		$iterator = \CIBlockElement::GetList($sort, $filter, false, $nav, $select);
		while ($product = $iterator->Fetch()){
			$this->allProductIds[] = $product['ID'];
		}

		$this->totalCount = count($this->allProductIds);
		$productIds = $this->allProductIds;
		if ($this->params->isPaginationEnabled()){

			$productIds = array_splice(
				$productIds,
				($this->params->getPageNumber() - 1) * $this->params->getPageProductCount(),
				$this->params->getPageProductCount()
			);
		}

		return $productIds;
	}

	protected function getFilter(): array
	{
		$filter = $this->params->getFilter();
		$filter['IBLOCK_ID'] = CATALOG_IBLOCK_ID;

		if ($this->params->isShowInactive()) {
			unset($filter['ACTIVE']);
			unset($filter['=ACTIVE']);
		} elseif ($this->params->isShowManagersOnlyProducts()) {
			unset($filter['ACTIVE']);
			unset($filter['=ACTIVE']);
			$filter[] = [
				'LOGIC' => 'OR',
				['ACTIVE' => 'Y'],
				['!PROPERTY_SHOW_FOR_MANAGERS_ONLY' => false],
			];
		} else{
			$filter['ACTIVE'] = 'Y';
		}

		if ($this->params->isIncludeSubsections()) {
			$filter['INCLUDE_SUBSECTIONS'] = 'Y';
		}

		if (!$this->params->isShowHiddenInCatalog())
		{
			$filter['PROPERTY_HIDE_IN_CATALOG'] = false;
		}

		if (!$this->params->isShowArchive()){
			$filter['!PROPERTY_ARCHIVE_VALUE'] = 'Да';
		}

		if (!$this->params->isShowAccessors()){
			$filter['PROPERTY_ACSESSUAR_SORT'] = false;
		}

		if (!$this->params->isShowComplects()){
			$filter['PROPERTY_COMPLECT_MAIN_PRODUCT'] = false;
		}

		if (!$this->params->isShowOutOfStock()) {
			$filter['PROPERTY_AVAILABLE_IN_STOCK'] = '1';
		}

		if ($this->params->getSectionId()){
			$filter['SECTION_ID'] = $this->params->getSectionId();
		}

		return $filter;
	}

	protected function getSort(): array
	{
		$sort = $this->params->getSort();

		if (!$sort){
			$sort = [
				'SORT' => 'asc,nulls',
				'ID' => 'desc'
			];
		}

		return $sort;
	}

	public function getParams(): CatalogQueryParams
	{
		return $this->params;
	}
}