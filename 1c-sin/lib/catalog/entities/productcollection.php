<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\Enums\PickupRestriction;
use Rusgeocom\Rusgeocom\Catalog\Products;
use Rusgeocom\Rusgeocom\Catalog\SectionTree;
use Rusgeocom\Rusgeocom\Personal\Services\PersonalService;
use Rusgeocom\Rusgeocom\Sale\Entities\CustomerAccount;
use Rusgeocom\Rusgeocom\Sale\Services\DiscountCalculator;
use Rusgeocom\Rusgeocom\Ui\Tools;
use Rusgeocom\Rusgeocom\Utils\Iblock;
use Rusgeocom\Rusgeocom\Utils\Settings;
use Rusgeocom\Rusgeocom\Utils\User;
use Rusgeocom\Search\Query\Collection;

class ProductCollection implements \JsonSerializable, \Iterator
{
	/** @var Product[] */
	private $products = [];
	private $position = 0;

	/**
	 * @param Product[] $products
	 */
	public function __construct(array $products = [])
	{
		$this->products = array_values($products);
	}

	public function add(Product $product): ProductCollection
	{
		$this->products[] = $product;
		return $this;
	}

	public function addCollection(ProductCollection $collection): ProductCollection
	{
		foreach ($collection->asArray() as $product) {
			$this->products[] = $product;
		}
		return $this;
	}

	public function resizeDetailPictures(array $size, array $smallSize = []): ProductCollection
	{
		foreach ($this->products as $product){
			$product->resizeDetailPicture($size, $smallSize);
		}

		return $this;
	}

	public function resizeForAccessorsTab(): ProductCollection
	{
		foreach ($this->products as $product) {
			$product->resizeForAccessorsTab();
		}

		return $this;
	}

	public function disableImagesSlider(): ProductCollection
	{
		foreach ($this->products as $product) {
			$product->setImageLimit(1);
		}

		return $this;
	}

	public function setMainButtonType(string $type): ProductCollection
	{
		foreach ($this->products as $product){
			$product->setMainButtonType($type);
		}

		return $this;
	}

	public function getById(int $id) : ?Product
	{
		foreach ($this->products as $product){
			if ($product->getId() === $id){
				return $product;
			}
		}

		return null;
	}

	/**
	 * @param array $ids
	 *
	 * @return Product[]
	 *
	 */
	public function getByIds(array $ids) : array
	{
		$collection = [];
		foreach ($ids as $id) {
			$collection[] = $this->getById($id);
		}

		return array_filter($collection);
	}

	public function first() : ?Product
	{
		return current($this->products) ?: null;
	}

	/**
	 * @return int[]
	 */
	public function getIds(): array
	{
		$ids = [];
		foreach ($this->products as $product){
			$ids[] = $product->getId();
		}

		return $ids;
	}

	public function fillManagerStocks(string $domain, int $currentSectionId): ProductCollection
	{
		if (!$this->getIds()){
			return $this;
		}
		$products = [];
		foreach ($this->products as $product) {
			$products[$product->getId()] = [
				'isComplect' => $product->isComplect(),
				'isVendorResetTime' => !!$product->getVendorResetTime(),
			];
		}

		$stocks = Tools::getStocksForManager($products, $domain);

		$isShowSectionToWholesalersMap = [];
		if ($currentSectionId) {
			$isShowSectionToWholesalersMap[$currentSectionId] = $this->isShowSectionToWholesalers($currentSectionId);
		}

		$isWholesalerProfile = PersonalService::isCurrentProfileWholesaler();
		$isCurrentUserWholesaler = User::isWholesaler();
		foreach ($this->products as $product){
			if (!isset($isShowSectionToWholesalersMap[$product->getSectionId()])) {
				$isShowSectionToWholesalersMap[$product->getSectionId()] = $this->isShowSectionToWholesalers($product->getSectionId());
			}

			if (
				$isCurrentUserWholesaler
				&& (!$isWholesalerProfile || !$product->isShowToWholesalers() || !$isShowSectionToWholesalersMap[$product->getSectionId()])
			) {
				continue;
			}

			$product->setManagerStocks($stocks[$product->getId()]);
		}

		return $this;
	}

	private function isShowSectionToWholesalers(int $idSection = 0): bool
	{
		if (!$idSection) {
			return true;
		}
		$elementSectionTree = SectionTree::getInstance()->getTree($idSection);
		return !(!$elementSectionTree["IS_SHOW_TO_WHOLESALERS"] || !$this->isShowSectionToWholesalers($elementSectionTree["PARENT_ID"] ?? 0));
	}

	public function fillWholesalePrices(): ProductCollection
	{
		$ids = $this->getIds();
		if (!$ids) {
			return $this;
		}

		foreach ($this->products as $product) {
			$product->setShowWholesalePrices(true);
		}

		return $this;
	}

	public function fillDiscountPreviews(): ProductCollection
	{
		$ids = $this->getIds();
		if (!$ids) {
			return $this;
		}

		foreach ($this->products as $product) {
			$product->setShowDiscountPreview(true);
		}

		return $this;
	}

	public function fillDiscountBags(CustomerAccount $account): ProductCollection
	{
		DiscountCalculator::fillDiscountBags($this, $account);

		return $this;
	}

	public function fillCalculatedPrices(CustomerAccount $account, bool $priceForDetailPage = false): ProductCollection
	{
		DiscountCalculator::fillCalculatedPrices($this, $account, $priceForDetailPage);

		return $this;
	}

	public function fillDiscountViewParams(): ProductCollection
	{
		$ids = $this->getIds();
		if (!$ids) {
			return $this;
		}

		$params = Catalog::getDiscountViewParams($ids);
		foreach ($this->products as $product) {
			if (!isset($params[$product->getId()])) {
				continue;
			}

			$productParams = $params[$product->getId()];
			$product->setDiscountViewParams(
				$productParams['GUEST_DISCOUNT_VIEW'],
				$productParams['DISCOUNT_DISPLAY_TYPE']
			);
		}

		return $this;
	}

	public function fillPickupRestrictionType(): ProductCollection
	{
		$restrictionXmlIds = Iblock::getEnumerationXmlIdByCodes(['RESTRICT_PICKUP'], CATALOG_IBLOCK_ID);

		foreach ($this->products as $product) {
			$product->setPickupRestriction(
				PickupRestriction::getByXmlId($restrictionXmlIds[$product->getPickupRestrictionId()] ?? '')
			);
		}

		return $this;
	}

	public function hideAuthorizationHint(): ProductCollection
	{
		foreach ($this->products as $product) {
			$product->hideAuthorizationHint();
		}

		return $this;
	}

	/**
	 * @return int[]
	 */
	public function getSectionIds(): array
	{
		$ids = [];
		foreach ($this->products as $product){
			$ids[] = $product->getSectionId();
		}
		return array_unique($ids);
	}

	/**
	 * @return Product[]
	 */
	public function asArray(): array
	{
		return $this->products;
	}

	public function jsonSerialize(): array
	{
		return $this->asArray();
	}

	public function getCount(): int
	{
		return count($this->products);
	}

	public function isEmpty(): bool
	{
		return $this->getCount() === 0;
	}

	public function current(): Product
	{
		return $this->products[$this->position];
	}

	public function next(): ?Product
	{
		return $this->products[++$this->position];
	}

	public function key(): int
	{
		return $this->position;
	}

	public function valid(): bool
	{
		return isset($this->products[$this->position]);
	}

	public function rewind(): void
	{
		$this->position = 0;
	}

	public function isAllInStock(): bool
	{
		foreach ($this->products as $product){
			if (!$product->inStock()){
				return false;
			}
		}

		return true;
	}
}