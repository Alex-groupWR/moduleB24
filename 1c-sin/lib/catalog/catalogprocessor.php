<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\PriceTable;
use CIBlockElement;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogQueryParams;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogResult;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductCollection;
use Rusgeocom\Rusgeocom\Catalog\Services\ProductFactory;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;

final class CatalogProcessor extends CatalogProcessorBase
{
	private array $products = [];

	public static function create(CatalogQueryParams $params): self
	{
		return new self($params);
	}

	private function __construct(CatalogQueryParams $params)
	{
		parent::__construct($params);
	}

	public function execute(): CatalogResult
	{
		// Защита от пустого массива ID
		$filter = $this->params->getFilter();
		if (isset($filter['ID']) && !$filter['ID']){
			return new CatalogResult(new ProductCollection(), 0);
		}

		$this->fillProducts();
		$this->fillPrices();

		$products = ProductFactory::getInstance()->makeProducts($this->products);
		$totalCount = $this->params->isPaginationEnabled() ? $this->totalCount : $products->getCount();

		return new CatalogResult($products, $totalCount, $this->getAllProductIds());
	}

	private function fillProducts(): void
	{
		$productIds = $this->getProductIds();
		if (!$productIds){
			return;
		}

		$select = [];
		foreach (ProductFactory::getSelectFields() as $field){
			$select['ELEMENT_' . $field] = 'IBLOCK_ELEMENT.' . $field;
		}

		$productsFields = [];
		$productsProperties = [];
		$propCodes = array_merge(ProductFactory::getSelectSinglePropCodes(), ProductFactory::getSelectMultiPropCodes());
		$iterator = IblockElementPropertyTable::query()
			->whereIn('IBLOCK_ELEMENT_ID', $productIds)
			->whereIn('IBLOCK_PROPERTY.CODE', $propCodes)
			->where('IBLOCK_PROPERTY.ACTIVE', 'Y')
			->setSelect($select)
			->addSelect('IBLOCK_PROPERTY.CODE', 'PROP_CODE')
			->addSelect('VALUE')
			->exec();
		while ($product = $iterator->fetch()){
			$productId = $product['ELEMENT_ID'];

			// Поля
			if (!$productsFields[$productId]){
				$productsFields[$productId] = [];
				foreach (ProductFactory::getSelectFields() as $field){
					$productsFields[$productId][$field] = $product['ELEMENT_' . $field] ?? null;
				}
			}

			// Свойства
			$productsProperties[$productId][$product['PROP_CODE']][] = $product['VALUE'];
		}

		foreach ($productIds as $productId){
			$fields = $productsFields[$productId];
			$props = $productsProperties[$productId];
			if ($fields && $props)
				$this->products[$productId] = $this->makeProduct($fields, $props);
		}
	}

	private function makeProduct(array $fields, array $props): array
	{
		$product = $fields;

		foreach (ProductFactory::getSelectSinglePropCodes() as $code){
			$product['PROPERTIES'][$code] = $props[$code][0] ?? null;
		}
		foreach (ProductFactory::getSelectMultiPropCodes() as $code){
			$product['PROPERTIES'][$code] = $props[$code] ?? [];
		}

		// Тут можно модифицировать значения

		return $product;
	}

	private function fillPrices(): void
	{
		$productToOfferMap = $this->getMainOffersForProducts(array_keys($this->products));
		$idsForPrices = [];
		foreach ($this->products as $product){
			if ($productToOfferMap[$product['ID']]){
				$idsForPrices[] = $productToOfferMap[$product['ID']];
			}
			$idsForPrices[] = $product['ID'];
		}

		$prices = $this->getPrices($idsForPrices);
		foreach ($this->products as $product){
			$offerId = $productToOfferMap[$product['ID']] ?: $product['ID'];
			$this->products[$product['ID']]['OFFER_ID'] = $offerId;
			$productPrices = $prices[$offerId] ?: [];
			if ($productPrices){
				$this->products[$product['ID']]['PRICES'] = array_map(
					static function (array $price) {
						return [
							'DISCOUNT_VALUE' => $price['PRICE'],
							'VALUE' => $price['PRICE'],
							'CURRENCY' => $price['CURRENCY'],
							'QUANTITY_FROM' => $price['QUANTITY_FROM'],
							'CATALOG_GROUP_ID' => $price['CATALOG_GROUP_ID'],
						];
					},
					$productPrices
				);
			}
			if ($offerId !== $product['ID']) {
				$productPrices = $prices[$product['ID']] ?: [];
				$this->products[$product['ID']]['BASE_PRICES'] = array_map(
					static function (array $price) {
						return [
							'DISCOUNT_VALUE' => $price['PRICE'],
							'VALUE' => $price['PRICE'],
							'CURRENCY' => $price['CURRENCY'],
							'QUANTITY_FROM' => $price['QUANTITY_FROM'],
							'CATALOG_GROUP_ID' => $price['CATALOG_GROUP_ID'],
						];
					},
					$productPrices
				);
			}
		}
	}

	public function getAllProductIds(): array
	{
		return $this->allProductIds;
	}

	private function getMainOffersForProducts(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$select = [
			'ID',
			'PROPERTY_CML2_LINK'
		];
		$filter = [
			'=PROPERTY_CML2_LINK' => $productIds,
			'=ACTIVE' => 'Y',
			'=PROPERTY_CHECK' => false
		];
		$sort = ['SORT' => 'ASC'];
		$offers = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)->getElementsByFilter($filter, $select, 0, $sort);
		$productToOfferMap = [];
		foreach ($offers as $offer){
			$productId = $offer['PROPERTIES']['CML2_LINK']['VALUE'];
			if (!$productToOfferMap[$productId]){
				$productToOfferMap[$productId] = $offer['ID'];
			}
		}

		return $productToOfferMap;
	}

	private function getPrices(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$prices = [];
		$iterator = PriceTable::query()
			->addSelect('PRODUCT_ID')
			->addSelect('PRICE')
			->addSelect('CURRENCY')
			->addSelect('CATALOG_GROUP_ID')
			->addSelect('QUANTITY_FROM')
			->addSelect('QUANTITY_TO')
			->whereIn('PRODUCT_ID', $productIds)
			->exec();
		while ($row = $iterator->fetch()){
			$prices[$row['PRODUCT_ID']][$row['CATALOG_GROUP_ID']] = $row;
		}

		return $prices;
	}
}