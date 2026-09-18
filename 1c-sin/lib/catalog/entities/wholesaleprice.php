<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class WholesalePrice implements \JsonSerializable
{
	private const NAME_PREFIX = 'Оптовая цена ';
	private const DEFAULT_AMOUNT_FOR_PRICE = 2;

	private int $level;
	private int $price;
	private int $priceOld;
	private int $countFor;
	private bool $hideOldPrice = false;

	public function __construct(int $level, int $price, int $priceOld, int $countFor = 0)
	{
		$this->level = $level;
		$this->price = $price;
		$this->priceOld = $priceOld;
		$this->countFor = $countFor;
	}

	public function getName(): string
	{
		return static::NAME_PREFIX . $this->level;
	}

	public function getLevel(): int
	{
		return $this->level;
	}

	public function getPrice(): int
	{
		return $this->price;
	}

	public function getPriceOld(): int
	{
		return $this->level === 1 ? $this->priceOld : 0;
	}

	public function getCountFor(): int
	{
		return $this->level > 1 ? ($this->countFor ?: static::DEFAULT_AMOUNT_FOR_PRICE) : 0;
	}

	public function hideOldPrice(): void
	{
		$this->hideOldPrice = true;
	}

	public function jsonSerialize(): array
	{
		return [
			'name' => $this->getName(),
			'price' => $this->getPrice(),
			'priceOld' => $this->hideOldPrice ? 0 : $this->getPriceOld(),
			'itemsCount' => $this->getCountFor(),
			'order' => $this->getLevel(),
		];
	}
}