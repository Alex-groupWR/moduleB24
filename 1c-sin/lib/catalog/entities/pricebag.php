<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Rusgeocom\Core\Discounts\Contracts\PriceBagInterface;
use Rusgeocom\Core\Discounts\Enums\PriceType as CorePriceType;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;

final class PriceBag implements IteratorAggregate, Countable, PriceBagInterface
{
	private array $prices = [];

	public function __construct(array $prices = [])
	{
		foreach ($prices as $priceTypeName => $price) {
			$this->add((string)$priceTypeName, $price);
		}
	}

	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->prices);
	}

	public function count(): int
	{
		return count($this->prices);
	}

	public function has(string $priceTypeName): bool
	{
		return (bool)$this->prices[$priceTypeName] ?? false;
	}

	public function get(string $priceTypeName): null|int|array
	{
		return $this->prices[$priceTypeName] ?? null;
	}

	public function add(string $priceTypeName, null|int|array $price): void
	{
		$this->prices[$priceTypeName] = $price;
	}

	public function getPriceByType(CorePriceType $type): ?int
	{
		return match ($type) {
			CorePriceType::Base, CorePriceType::Retail => $this->get(BASE_PRICE_CODE),
			CorePriceType::Old => $this->get(PriceTypes::OLD_PRICE_KEY),
		};
	}

	public function getDefaultPrice(): int
	{
		return $this->get(BASE_PRICE_CODE) ?? 0;
	}
}