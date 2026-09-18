<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use JsonSerializable;
use Rusgeocom\Core\Discounts\Enums\DiscountAmountView;
use Rusgeocom\Core\Utils\PriceUtils;
use Rusgeocom\Rusgeocom\Catalog\Price as CatalogPrice;
use Rusgeocom\Rusgeocom\Ui\Localizer;
use function Symfony\Component\Translation\t;

final readonly class ComplectConfigurator implements JsonSerializable
{
	/** @var Collection<int, ComplectProduct>  */
	private Collection $selectedParts;
	private string $code;

	/**
	 * @param Product $mainProduct
	 * @param Collection<int, array{group: ComplectGroup, products: ComplectProduct[]}> $groupsProducts
	 * @param Collection<int, string> $selectedProductCodes
	 * @param Collection<int, string[]> $productAdvantages
	 * @param Localizer|null $localizer
	 * @param bool $forAuthorized
	 */
	public function __construct(
		private Product $mainProduct,
		private Collection $groupsProducts,
		Collection $selectedProductCodes,
		private Collection $productAdvantages,
		private ?Localizer $localizer = null,
		private bool $forAuthorized = false,
	) {
		$selectedParts = Collection::empty();
		foreach ($selectedProductCodes as $groupId => $selectedProductCode) {
			$groupProduct = $this->groupsProducts->first(
				static fn (array $groupData): bool => $groupData['group']->getId() === $groupId
			);
			$selectedProduct = $groupProduct['products'][$selectedProductCode] ?? null;
			if (!$groupProduct || !$selectedProduct) {
				throw new InvalidArgumentException('Выбранные товары не соответствуют группам');
			}

			$selectedParts->put($groupId, $selectedProduct);
		}

		$this->selectedParts = $selectedParts;
		$this->code = $this->makeCode();
	}

	private function makeCode(): string
	{
		$ids = Collection::make([(string)$this->mainProduct->getId()]);
		$sortedGroups = $this->groupsProducts->map(static fn (array $groupData) => $groupData['group'])
			->sortBy(static fn (ComplectGroup $group) => $group->getRowId());
		foreach ($sortedGroups as $group) {
			/** @var ComplectProduct $product */
			if ($product = $this->selectedParts->get($group->getId())) {
				$ids->add($product->getCode());
			}
		}

		return md5($ids->implode('-'));
	}

	public function getCode(): string
	{
		return $this->code;
	}

	public function getMainProduct(): Product
	{
		return $this->mainProduct;
	}

	/**
	 * @return Collection<int, ComplectProduct>
	 */
	public function getSelectedParts(): Collection
	{
		return $this->selectedParts;
	}

	/**
	 * @return Collection<int, array{group: ComplectGroup, products: ComplectProduct[]}>
	 */
	public function getGroupsProducts(): Collection
	{
		return $this->groupsProducts;
	}

	private function makeSummary(): array
	{
		return [
			'count' => $this->getSelectedParts()->count() + 1,
			'names' => $this->getSelectedParts()
				->map(fn(ComplectProduct $product): string => $product->getFormattedName())
				->prepend($this->getMainProduct()->getName($this->localizer)),
			'selectedProducts' => $this->getSelectedParts()
				->map(
					static function(ComplectProduct $product, int $groupId): array {
						return [
							'groupId' => $groupId,
							'productId' => $product->getId(),
							'productCode' => $product->getCode(),
						];
					},
				)->values(),
			'price' => $this->makePrice(),
		];
	}

	private function makePrice(): array
	{
		$mainProduct = $this->getMainProduct();
		$priceBeforeDiscount = $mainProduct->getOldPrice() ?: $mainProduct->getBasePrice();
		$priceAfterDiscount = $mainProduct->getCurrentPrice();
		$retailPrice = $mainProduct->getBasePrice();
		$hasGreenPrice = $mainProduct->hasGreenPrice();
		$baseSegmentPrice = $mainProduct
			->getCalculatedPrice()
			?->getBaseSegmentDiscount()
			?->getDiscountPrice() ?? $mainProduct->getCurrentPrice();

		foreach ($this->getSelectedParts() as $selectedPart) {
			$priceBeforeDiscount += $selectedPart->getOldPrice() ?: $selectedPart->getBasePrice();
			$priceAfterDiscount += $selectedPart->getPrice();
			$retailPrice += $selectedPart->getBasePrice();
			if (!$hasGreenPrice && $selectedPart->hasGreenPrice()) {
				$hasGreenPrice = true;
			}
			$baseSegmentPrice += $selectedPart->getBaseSegmentPrice();
		}

		$mainProductDiscountAmountView = $mainProduct->getDiscountDisplay() ?? DiscountAmountView::Total;
		$currentDiscount = $priceBeforeDiscount - $priceAfterDiscount;
		if ($mainProductDiscountAmountView === DiscountAmountView::Percent && $currentDiscount > 0) {
			$currentDiscount = PriceUtils::percent($priceBeforeDiscount, $currentDiscount);
		}
		$calculatedPrice = $mainProduct->getCalculatedPrice();
		$hasFirstDiscount = $calculatedPrice?->hasFirstDiscount() ?? false;

		return [
			'price' => $priceAfterDiscount,
			'priceOld' => $priceBeforeDiscount,
			'priceRetail' => $retailPrice,
			'oldPrice' => $priceBeforeDiscount,
			'retailPrice' => $retailPrice,
			'currentDiscount' => $currentDiscount,
			'discountDisplay' => $mainProductDiscountAmountView,
			'hasAuthorizationLabel' => $hasGreenPrice && !$this->forAuthorized,
			'hasGreenPrice' => $hasGreenPrice,
			'discountHint' => $mainProduct->getDiscountHint(),
			'hasFirstDiscount' => $hasFirstDiscount,
			'firstDiscountHint' => $hasFirstDiscount ? Product::FIRST_DISCOUNT_HINT : null,
			'bestPrice' => $this->makeBestPrice(
				$priceAfterDiscount,
				$retailPrice,
				$baseSegmentPrice,
				$priceBeforeDiscount,
				$mainProductDiscountAmountView,
			),
			'forComplects' => true,
		];
	}

	private function makeBestPrice(
		int $price,
		int $basePrice,
		int $baseSegmentPrice,
		int $oldPrice,
		DiscountAmountView $discountAmountView,
	): ?array {
		if ($basePrice <= $price || $basePrice <= $baseSegmentPrice || $price >= $baseSegmentPrice) {
			return null;
		}

		$baseSegmentDiscount = max(0,$oldPrice - $baseSegmentPrice);
		$currentDiscount = max(0,$oldPrice - $price);

		return [
			'retailPrice' => $basePrice,
			'firstDiscount' => $this->makeBestPriceRow(
				$baseSegmentPrice,
				$discountAmountView === DiscountAmountView::Percent
					? PriceUtils::percent($oldPrice, $baseSegmentDiscount)
					: $baseSegmentDiscount,
				$discountAmountView,
			),
			'currentDiscount' => $this->makeBestPriceRow(
				$price,
				$discountAmountView === DiscountAmountView::Percent
					? PriceUtils::percent($oldPrice, $currentDiscount)
					: $currentDiscount,
				$discountAmountView,
			),
			'actualDate' => date('d.m.Y'),
		];
	}

	private function makeBestPriceRow(
		int $discountPrice,
		int $discount,
		DiscountAmountView $discountAmountView
	): array {
		return [
			'amount' => $discount,
			'type' => $discountAmountView->value,
			'price' => $discountPrice,
		];
	}

	private function makeProductAdvantages(): ?array
	{
		if ($this->productAdvantages->isEmpty()) {
			return null;
		}

		$advantages = [];
		foreach ($this->productAdvantages as $productId => $advantagesList) {
			$advantages[] = [
				'productId' => $productId,
				'advantages' => $advantagesList ?: null,
			];
		}

		return $advantages;
	}

	public function jsonSerialize(): array
	{
		return [
			'code' => $this->getCode(),
			'summary' => $this->makeSummary(),
			'productAdvantages' => $this->makeProductAdvantages(),
			'groups' => $this->getGroupsProducts()->map(
				static function (array $group): array {
					return [
						'group' => $group['group'],
						'products' => array_values($group['products']),
					];
				}
			),
		];
	}
}
