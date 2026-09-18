<?php

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Illuminate\Support\Collection;

final readonly class CatalogResultSlim
{
	/**
	 * @param Collection<int> $productIds
	 * @param int $totalCount
	 */
	public function __construct(
		private Collection $productIds,
		private int $totalCount
	) {
	}

	public static function createEmpty(): self
	{
		return new self(Collection::empty(), 0);
	}

	public function getTotalCount(): int
	{
		return $this->totalCount;
	}

	/**
	 * @return Collection<int>
	 */
	public function getProductIds(): Collection
	{
		return $this->productIds;
	}

	public function getPageCount(int $pageSize): int
	{
		if ($pageSize <= 0) {
			return 0;
		}

		return (int)ceil($this->getTotalCount() / $pageSize);
	}
}