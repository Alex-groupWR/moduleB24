<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;
use Rusgeocom\Rusgeocom\Geoip\BranchCityService;
use Rusgeocom\Rusgeocom\Geoip\GeoLocation;
use Rusgeocom\Rusgeocom\Utils\Environment;
use Rusgeocom\Rusgeocom\Utils\Settings;
use Rusgeocom\Rusgeocom\Utils\User;

class CatalogQueryParams
{
	private $filter = [];
	private $pageProductCount = Catalog::PAGE_PRODUCT_COUNT;
	private $pageNumber = 1;
	private $sectionId = 0;
	private $isShowAccessors = false;
	private $isShowComplects = false;
	private $isShowHiddenInCatalog = true;
	private $isIncludeSubsections = true;
	private $isShowArchive = false;
	private $useIdSort = false;
	private $sort = [];
	private $isPaginationEnabled = true;
	private $needManagerStocks = true;
	private $needWholesalePrices = false;
	private $needDiscountPreview = false;
	private $needDiscountBags = false;
	private $domain;
	private $isShowOutOfStock = true;
	private bool $isShowInactive = false;
	private bool $needDetailPriceCalculation = false;

	public static function create(): CatalogQueryParams
	{
		return new static();
	}

	public function __construct()
	{
		$this->domain = BranchCityService::getInstance()->getCurrentCity()->getDomain();
		$this->pageProductCount = Settings::getPageProductCountInSection() ?: Catalog::PAGE_PRODUCT_COUNT;
		$this->needDiscountBags = Environment::isNewDiscountsEnabled();
	}

	public function disablePagination(): CatalogQueryParams
	{
		$this->isPaginationEnabled = false;
		return $this;
	}

	public function isPaginationEnabled(): bool
	{
		return $this->isPaginationEnabled;
	}

	/**
	 * @param int[] $productIds
	 * @return $this
	 */
	public function setProductIds(array $productIds): CatalogQueryParams
	{
		$this->addFilter('ID', $productIds);
		return $this;
	}

	public function addFilter(string $key, $value): CatalogQueryParams
	{
		$this->filter[$key] = $value;
		return $this;
	}

	public function setFilter(array $filter): CatalogQueryParams
	{
		$this->filter = $filter;
		return $this;
	}

	public function getFilter(): array
	{
		return $this->filter;
	}

	public function setPageProductCount(int $pageProductCount): CatalogQueryParams
	{
		$this->pageProductCount = $pageProductCount;
		return $this;
	}

	public function getPageProductCount(): int
	{
		return $this->pageProductCount;
	}

	public function setShowHiddenInCatalog(bool $isShowHiddenInCatalog): CatalogQueryParams
	{
		$this->isShowHiddenInCatalog = $isShowHiddenInCatalog;
		return $this;
	}

	public function isShowHiddenInCatalog(): bool
	{
		return $this->isShowHiddenInCatalog;
	}

	public function setIncludeSubsections(bool $isIncludeSubsections): CatalogQueryParams
	{
		$this->isIncludeSubsections = $isIncludeSubsections;
		return $this;
	}

	public function isIncludeSubsections(): bool
	{
		return $this->isIncludeSubsections;
	}

	public function setPageNumber(int $pageNumber): CatalogQueryParams
	{
		$this->pageNumber = $pageNumber;
		return $this;
	}

	public function getPageNumber(): int
	{
		return $this->pageNumber;
	}

	public function setSort(array $sort): CatalogQueryParams
	{
		$this->sort = $sort;
		return $this;
	}

	public function addSort(string $key, $value): CatalogQueryParams
	{
		$this->sort[$key] = $value;
		return $this;
	}

	public function getSort(): array
	{
		return $this->sort;
	}

	public function setShowAccessors(bool $value): CatalogQueryParams
	{
		$this->isShowAccessors = $value;
		return $this;
	}

	public function isShowAccessors(): bool
	{
		return $this->isShowAccessors;
	}

	public function setShowArchive(bool $value): CatalogQueryParams
	{
		$this->isShowArchive = $value;
		return $this;
	}

	public function isShowArchive(): bool
	{
		return $this->isShowArchive;
	}

	public function setShowComplects(bool $value): CatalogQueryParams
	{
		$this->isShowComplects = $value;
		return $this;
	}

	public function isShowComplects(): bool
	{
		return $this->isShowComplects;
	}

	public function getSectionId(): int
	{
		return $this->sectionId;
	}

	public function setSectionId(int $sectionId): CatalogQueryParams
	{
		$this->sectionId = $sectionId;
		return $this;
	}

	public function setNeedManagerStocks(bool $value): CatalogQueryParams
	{
		$this->needManagerStocks = $value;
		return $this;
	}

	public function setNeedWholesalePrices(bool $value): CatalogQueryParams
	{
		$this->needWholesalePrices = $value;
		return $this;
	}

	public function setNeedDiscountPreview(bool $value): CatalogQueryParams
	{
		$this->needDiscountPreview = $value;
		return $this;
	}

	public function setNeedDiscountBags(bool $value): CatalogQueryParams
	{
		$this->needDiscountBags = $value;
		return $this;
	}

	public function needManagerStocks(): bool
	{
		return $this->needManagerStocks
			&& (
				User::isManager()
				|| User::isDealer()
				|| User::isProtectedRusgeocomManager()
				|| User::isWholesaler()
			);
	}

	public function needWholesalePrices(): bool
	{
		return $this->needWholesalePrices && PriceTypes::hasWholesalePricesAccess();
	}

	public function needDiscountPreview(): bool
	{
		return $this->needDiscountPreview && User::hasDiscountPreviewAccess();
	}

	public function needDiscountBags(): bool
	{
		return $this->needDiscountBags;
	}

	public function needDetailPriceCalculation(): bool
	{
		return $this->needDetailPriceCalculation;
	}

	public function setNeedDetailPriceCalculation(bool $value): self
	{
		$this->needDetailPriceCalculation = $value;
		return $this;
	}

	public function getDomain(): string
	{
		return $this->domain;
	}

	public function setDomain(string $value): CatalogQueryParams
	{
		$this->domain = $value;
		return $this;
	}

	public function setShowOutOfStock(bool $value): CatalogQueryParams
	{
		$this->isShowOutOfStock = $value;
		return $this;
	}

	public function isShowOutOfStock(): bool
	{
		return $this->isShowOutOfStock;
	}

	public function setShowInactive(bool $value): CatalogQueryParams
	{
		$this->isShowInactive = $value;
		return $this;
	}

	public function isShowInactive(): bool
	{
		return $this->isShowInactive;
	}

	public function getHash(): string
	{
		$keys = [
			$this->filter,
			$this->pageProductCount,
			$this->pageNumber,
			$this->sectionId,
			$this->isShowAccessors,
			$this->isShowComplects,
			$this->isShowArchive,
			$this->useIdSort,
			$this->sort,
			$this->isPaginationEnabled,
			$this->needManagerStocks,
			$this->domain,
			$this->isShowOutOfStock,
			$this->isShowManagersOnlyProducts(),
			$this->isShowInactive,
		];

		return md5(json_encode($keys));
	}

	/**
	 * Показывать неактивные товары менеджерам (если стоит галка)
	 *
	 * @return bool
	 */
	public function isShowManagersOnlyProducts(): bool
	{
		return User::hasManagerOnlyProductsAccess();
	}
}