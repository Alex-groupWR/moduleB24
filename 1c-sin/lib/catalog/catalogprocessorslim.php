<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\PriceTable;
use CIBlockElement;
use Illuminate\Support\Collection;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogQueryParams;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogResult;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogResultSlim;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductCollection;
use Rusgeocom\Rusgeocom\Catalog\Services\ProductFactory;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;

final class CatalogProcessorSlim extends CatalogProcessorBase
{
	private function __construct(CatalogQueryParams $params)
	{
		parent::__construct($params);
	}

	public static function create(CatalogQueryParams $params): self
	{
		return new self($params);
	}

	public function execute(): CatalogResultSlim
	{
		// Защита от пустого массива ID
		$filter = $this->params->getFilter();
		if (isset($filter['ID']) && !$filter['ID']){
			return CatalogResultSlim::createEmpty();
		}

		$productIds = Collection::make($this->getProductIds());
		$totalCount = $this->params->isPaginationEnabled() ? $this->totalCount : $productIds->count();

		return new CatalogResultSlim($productIds, $totalCount);
	}
}