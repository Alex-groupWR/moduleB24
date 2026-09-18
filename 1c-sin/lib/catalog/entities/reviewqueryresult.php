<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

final readonly class ReviewQueryResult
{
	public function __construct(
		private ReviewCollection $reviews,
		private int $totalCount,
		private int $pageNumber,
		private int $pageSize,
	)
	{
	}

	public function getReviewCollection(): ReviewCollection
	{
		return $this->reviews;
	}

	public function getTotalCount(): int
	{
		return $this->totalCount;
	}

	public function getPageNumber(): int
	{
		return $this->pageNumber;
	}

	public function getPageSize(): int
	{
		return $this->pageSize;
	}

	public function getPageCount(): int
	{
		if ($this->pageSize <= 0) {
			return 0;
		}

		return (int)ceil($this->getTotalCount() / $this->pageSize);
	}
}