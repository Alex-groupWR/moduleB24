<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Rusgeocom\Rusgeocom\Api\Pages\BasePage;
use Rusgeocom\Rusgeocom\Catalog\Filter as CatalogFilter;
use Rusgeocom\Rusgeocom\Catalog\Entities\Filter;
use Rusgeocom\Rusgeocom\Types\Uri;

abstract class FilterablePage extends BasePage
{
	protected Uri $uri;
	protected array $sectionIds = [];
	protected array $productIds = [];
	protected array $allowedPropertiesIds = [];

	protected function executeFilter(bool $showArchive = false): Filter
	{
		return CatalogFilter::executeKombox(
			$this->uri,
			$this->sectionIds,
			$this->getParams(),
			true,
			true,
			$showArchive,
			$this->productIds,
			$this->allowedPropertiesIds
		);
	}

	protected function getPageNumber(): int
	{
		return $this->getParam('PAGEN_1', 1) ?: $this->getParam('PAGEN_2', 1) ?: 1;
	}
}
