<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Logema\Utils\DataAccess\IblockPropertyValueExtractor;
use Rusgeocom\Core\Discounts\Contracts\CalculatedPriceInterface;
use Rusgeocom\Core\Discounts\Dto\CalculatedPrice;
use Rusgeocom\Core\Discounts\Enums\GuestDiscountView;
use Rusgeocom\Rusgeocom\Catalog\Price;
use Rusgeocom\Rusgeocom\Sale\Entities\Discounts\DiscountBag;

abstract class ProductOffer implements \JsonSerializable, ReceiptProduct
{
	protected $id = 0;
	protected $productId = 0;
	protected $price = 0;
	protected int $basePrice = 0;
	protected $priceWithDiscount = 0;
	protected $oldPrice = 0;
	protected $name = '';
	protected $title = '';
	protected $article = '';
	protected $disabledOptionIds = [];
	protected $requiredOptionIds = [];
	protected int $discountValue = 0;
	protected $additionalWorkingDays;
	protected bool $needVatInReceipt = false;
	protected ?DiscountBag $discountBag = null;
	protected bool $displayDiscountPreview = true;
	protected bool $basePriceForPreview = false;
	protected ?CalculatedPriceInterface $calculatedPrice = null;

	public function __construct(array $iblockElement)
	{
		$props = IblockPropertyValueExtractor::forElement($iblockElement);
		Price::fillPriceForProduct($iblockElement);

		$this->id = $iblockElement['ID'];
		$this->productId = $props['CML2_LINK'] ?: 0;
		$this->price = (int)$iblockElement['PRICE'];
		$this->oldPrice = (int)$iblockElement['OLD_PRICE'];
		$this->basePrice = Price::getPriceForProduct(BASE_PRICE_ID, $iblockElement) ?? 0;
		$this->name = htmlspecialchars_decode($iblockElement['NAME']);
		$this->title = $props['H1'] ? htmlspecialchars_decode($props['H1']) : $this->getName();
		$this->article = $props['ARTIKUL'] ?: '';
		$this->additionalWorkingDays = $iblockElement['PROPERTIES']['WORK_DAYS_ADD']['VALUE'] ?? '';
		$this->needVatInReceipt = empty($props['RECEIPT_WITHOUT_VAT']);
		if ($props['ID_NOACTIV']){
			foreach (explode(';', $props['ID_NOACTIV']) as $id){
				$id = intval(trim($id));
				if ($id){
					$this->disabledOptionIds[] = $id;
				}
			}
		}
		if ($props['REQUIRED_IDS']){
			foreach (explode(';', $props['REQUIRED_IDS']) as $id){
				$id = intval(trim($id));
				if ($id){
					$this->requiredOptionIds[] = $id;
				}
			}
		}
	}

	public function getAdditionalWorkingDays(): ?string
	{
		return $this->additionalWorkingDays;
	}

	public function getProductId(): int
	{
		return $this->productId;
	}

	public function getBasePrice(): int
	{
		return $this->basePrice;
	}

	public function getPrice(): int
	{
		return $this->price;
	}

	public function needVatInReceipt(): bool
	{
		return $this->needVatInReceipt;
	}

	public function getPriceWithDiscount(): int
	{
		$calculatedPrice = $this->getCalculatedPrice();
		if ($calculatedPrice) {
			if ($calculatedPrice->isEmpty()) {
				return 0;
			}

			if ($this->displayDiscountPreview && $this->basePriceForPreview) {
				return $this->getBasePrice();
			}

			if (!$this->displayDiscountPreview) {
				return $calculatedPrice->getPrice();
			}

			$oldPrice = $calculatedPrice->getOldPrice() ?: $this->getBasePrice();
			return $oldPrice - $calculatedPrice->getDiscountSum();
		}

		$discountBag = $this->getDiscountBag();
		if ($discountBag) {
			if ($this->displayDiscountPreview && $this->basePriceForPreview) {
				return $this->getBasePrice();
			}

			$discountAmount = $this->displayDiscountPreview
				? $discountBag->getAuthorizedDiscount()
				: $discountBag->getPriorityDiscount();
			return $this->getBasePrice() - $discountAmount;
		}

		return $this->priceWithDiscount;
	}

	public function getActualPrice(): int
	{
		$calculatedPrice = $this->getCalculatedPrice();
		if ($calculatedPrice) {
			return $calculatedPrice->getPreviewType() !== GuestDiscountView::None
				? $calculatedPrice->getPreviewPrice()
				: $this->getBasePrice();
		}

		$discountBag = $this->getDiscountBag();
		if ($discountBag) {
			return $this->getBasePrice() - $discountBag->getPriorityDiscount();
		}

		return $this->priceWithDiscount;
	}

	public function getCalculatedPrice(): ?CalculatedPriceInterface
	{
		return $this->calculatedPrice;
	}

	public function setDiscountValue(int $price): self
	{
		$this->discountValue = $price;
		return $this;
	}

	public function getDiscountValue(): int
	{
		return $this->discountValue;
	}

	public function getPriceFormatted(): string
	{
		return Price::format($this->getBasePrice());
	}

	public function getOldPrice(): int
	{
		return $this->oldPrice ?: $this->getBasePrice();
	}

	public function getOldPriceFormatted(): string
	{
		return Price::format($this->getOldPrice());
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getArticle(): string
	{
		return $this->article;
	}

	public function getName(): string
	{
		return $this->name;
	}

	/**
	 * @return int[]
	 */
	public function getDisabledOptionIds(): array
	{
		return $this->disabledOptionIds;
	}

	/**
	 * @return int[]
	 */
	public function getRequiredOptionIds(): array
	{
		return $this->requiredOptionIds;
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function setDiscountBag(?DiscountBag $discountBag): void
	{
		$this->discountBag = $discountBag;
	}

	public function getDiscountBag(): ?DiscountBag
	{
		return $this->discountBag;
	}

	public function hideDiscountPreview(): void
	{
		$this->displayDiscountPreview = false;
	}

	public function useBasePriceForPreview(): void
	{
		$this->basePriceForPreview = true;
	}

	public function setDiscountsFromProduct(Product $product): void
	{
		if ($product->getId() !== $this->getProductId()) {
			return;
		}

		$this->setDiscountBag($product->getDiscountBag());
		$this->setCalculatedPrice($product->getCalculatedPrice());
		if (!$product->hasAuthorizationDiscountLabel()) {
			$this->hideDiscountPreview();
		}
		if ($product->hasAuthorizationDiscountLabel() && $product->hasFirstDiscount()) {
			$this->useBasePriceForPreview();
		}
	}

	public function setCalculatedPrice(?CalculatedPrice $calculatedPrice): void
	{
		$this->calculatedPrice = $calculatedPrice;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'price' => $this->getPriceWithDiscount(),
			'oldPrice' => $this->getBasePrice(),
			'name' => $this->getName(),
			'article' => $this->getArticle(),
			'disableIds' => $this->getDisabledOptionIds(),
			'requireIds' => $this->getRequiredOptionIds(),
		];
	}
}