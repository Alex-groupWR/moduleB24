<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use JsonSerializable;
use Rusgeocom\Core\Discounts\Enums\DiscountAmountView;

final readonly class ComplectProduct implements JsonSerializable
{
	/**
	 * @param int $id
	 * @param string $code
	 * @param string $name
	 * @param string $url
	 * @param array $images
	 * @param string $mainButton
	 * @param bool $allowCompare
	 * @param bool $managerPreview
	 * @param int $price
	 * @param int $oldPrice
	 * @param int $basePrice
	 * @param int $baseSegmentPrice
	 * @param int $discountPercent
	 * @param bool $hasGreenPrice
	 * @param ManagerStocksData[]|WholesalerStocksData[] $stocks
	 * @param WholesalePrice[]|null $wholesalePrices
	 * @param int $quantity
	 * @param int $sort
	 */
	public function __construct(
		private int $id,
		private string $code,
		private string $name,
		private string $url,
		private array $images,
		private string $mainButton,
		private bool $allowCompare,
		private bool $managerPreview,
		private int $price,
		private int $oldPrice,
		private int $basePrice,
		private int $baseSegmentPrice,
		private int $discountPercent,
		private bool $hasGreenPrice,
		private array $stocks,
		private ?array $wholesalePrices = null,
		private int $quantity = 1,
		private int $sort = 0,
	) {
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getFormattedName(): string
	{
		if ($this->quantity > 1) {
			return $this->name . ' - ' . $this->quantity . 'шт';
		}

		return $this->name;
	}

	public function getQuantity(): int
	{
		return $this->quantity;
	}

	public function getImages(): array
	{
		return $this->images;
	}

	public function getUrl(): string
	{
		return $this->url;
	}

	public function getMainButton(): string
	{
		return $this->mainButton;
	}

	public function isAllowCompare(): bool
	{
		return $this->allowCompare;
	}

	public function isManagerPreview(): bool
	{
		return $this->managerPreview;
	}

	public function getOldPrice(): int
	{
		return $this->oldPrice;
	}

	public function getPrice(): int
	{
		return $this->price;
	}

	public function getBaseSegmentPrice(): int
	{
		return $this->baseSegmentPrice;
	}

	public function getBasePrice(): int
	{
		return $this->basePrice;
	}

	public function getDiscountPercent(): int
	{
		return $this->discountPercent;
	}

	public function hasGreenPrice(): bool
	{
		return $this->hasGreenPrice;
	}

	/**
	 * @return ManagerStocksData[]|WholesalerStocksData[]
	 */
	public function getStocks(): array
	{
		return $this->stocks;
	}

	/**
	 * @return WholesalePrice[]|null
	 */
	public function getWholesalePrices(): ?array
	{
		return $this->wholesalePrices;
	}

	public function getSort(): int
	{
		return $this->sort;
	}

	public function makePrice(): array
	{
		return [
			'price' => $this->getPrice(),
			'priceOld' => $this->getOldPrice(),
			'priceRetail' => $this->getBasePrice(),
			'currentDiscount' => $this->getDiscountPercent(),
			'discountDisplay' => DiscountAmountView::Percent,
			'hasAuthorizationLabel' => false,
			'hasGreenPrice' => $this->hasGreenPrice(),
			'discountHint' => null,
			'hasFirstDiscount' => false,
			'firstDiscountHint' => null,
			'bestPrice' => null,
			'forComplects' => true,
		];
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'code' => $this->getCode(),
			'name' => $this->getName(),
			'formattedName' => $this->getFormattedName(),
			'url' => $this->getUrl(),
			'images' => $this->getImages(),
			'mainButton' => $this->getMainButton(),
			'allowCompare' => $this->isAllowCompare(),
			'managerPreview' => $this->isManagerPreview(),
			'price' => $this->makePrice(),
			'manager' => $this->getStocks(),
			'wholesalePrices' => $this->getWholesalePrices(),
			'sort' => $this->getSort(),
		];
	}
}
