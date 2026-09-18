<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

final readonly class ComplectGroupItem
{
	public function __construct(
		private int $index,
		private int $productId,
		private int $quantity,
	) {
	}

	public function getIndex(): int
	{
		return $this->index;
	}

	public function getProductId(): int
	{
		return $this->productId;
	}

	public function getQuantity(): int
	{
		return $this->quantity;
	}

	public function getKey(): string
	{
		return $this->getProductId() . '-' . $this->getQuantity();
	}
}
