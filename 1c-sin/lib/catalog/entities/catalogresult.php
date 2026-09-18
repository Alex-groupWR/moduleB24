<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class CatalogResult
{
	private $products;
	private $totalCount;
	private $allProductIds;

	public function __construct(ProductCollection $products, int $totalCount, $allProductIds = [])
	{
		$this->products = $products;
		$this->totalCount = $totalCount;
		$this->allProductIds = $allProductIds;
	}

	public static function createEmpty(): CatalogResult
	{
		return new static(new ProductCollection([]), 0);
	}

	public function getProducts(): ProductCollection
	{
		return $this->products;
	}

	public function getTotalCount(): int
	{
		return $this->totalCount;
	}

	public function getAllProductIds(): array
	{
		return $this->allProductIds;
	}

	public function getPageCount(int $pageSize): int
	{
		if ($pageSize <= 0) {
			return 0;
		}

		return (int)ceil($this->getTotalCount() / $pageSize);
	}
}