<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Illuminate\Support\Str;

class PriceType
{
	private int $id;
	private string $name;

	public function __construct(int $id, string $name)
	{
		$this->id = $id;
		$this->name = $name;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getPriceProperty(): string
	{
		return 'CATALOG_PRICE_' . $this->getId();
	}

	public function getCurrencyProperty(): string
	{
		return 'CATALOG_CURRENCY_' . $this->getId();
	}

	public function getSortField(): string
	{
		return 'PRICE' . ($this->isBasePrice() ? '' : '_' . Str::upper($this->getName()));
	}

	public function isEqual(PriceType $priceType): bool
	{
		return $this->getId() === $priceType->getId();
	}

	public function isBasePrice(): bool
	{
		return $this->getId() === BASE_PRICE_ID;
	}
}