<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Fields;
use Exception;
use Illuminate\Support\Collection;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogQueryParams;
use Rusgeocom\Rusgeocom\Catalog\Entities\Product;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductCollection;
use Rusgeocom\Rusgeocom\Geoip\BranchCityService;
use Rusgeocom\Rusgeocom\Sale\Statistics\SortHelpTable;
use Rusgeocom\Rusgeocom\Sale\Statistics\StatisticsTable;
use Rusgeocom\Rusgeocom\Stores\StoreService;
use Rusgeocom\Rusgeocom\Ui\Sort;
use Rusgeocom\Rusgeocom\Ui\SortConditionHelper;

class Accessories
{
	protected static function getAccessorGroupIdsForProduct(int $productId): array
	{
		return static::getTargetProduct($productId)['PROPERTIES']['ACSESS_NEW']['VALUE'] ?: [];
	}

	protected static function getAccessorGroupIdsForProducts(array $productIds): array
	{
		$products = static::getTargetProducts($productIds);

		$gruopIds = [];
		foreach ($products as $product) {
			foreach ($product['PROPERTIES']['ACSESS_NEW']['VALUE'] as $groupId) {
				$gruopIds[$groupId][] = $product['ID'];
			}
		}

		return $gruopIds;
	}

	public static function getAccessorGroupsForProduct(int $productId): array
	{
		$accessorGroupIds = static::getAccessorGroupIdsForProduct($productId);

		if (!$accessorGroupIds) {
			return [];
		}

		return static::getGroups($accessorGroupIds, ACCESSORIES_IBLOCK_ID);
	}

	protected static function getZondGroupIdsForProduct(int $productId): array
	{
		return static::getTargetProduct($productId)['PROPERTIES']['ZONDS_NEW']['VALUE'] ?: [];
	}

	public static function getZondGroupsForProduct(int $productId): array
	{
		$product = static::getTargetProduct($productId);
		$accessorGroupIds = $product['PROPERTIES']['ZONDS_NEW']['VALUE'];
		if (!$accessorGroupIds) {
			return [];
		}

		return static::getGroups($accessorGroupIds, ZONDS_IBLOCK_ID);
	}

	private static function getParamsByProductIds(array $productIds, bool $showOutOfStock = true): CatalogQueryParams
	{
		$filter = static::makeProductFilter($productIds);

		return CatalogQueryParams::create()
			->disablePagination()
			->setShowAccessors(true)
			->setShowComplects(true)
			->setShowOutOfStock($showOutOfStock)
			->setFilter($filter)
			->addSort('PROPERTY_AVAILABLE_IN_STOCK', 'DESC')
			->addSort('PROPERTY_TRANSIT_POSTAV', 'DESC')
			->addSort('ID', $productIds);
	}

	private static function makeProductFilter(array $productIds): array
	{
		return [
			'ID' => $productIds,
			'ACTIVE' => 'Y',
			'!PROPERTY_ON_REQUEST_VALUE' => 'Да',
			'!PROPERTY_PRICE_ON_REQUEST_VALUE' => 'Да',
			[
				'LOGIC' => 'OR',
				'PROPERTY_AVAILABLE_IN_STOCK' => '1',
				[
					'LOGIC' => 'AND',
					'PROPERTY_AVAILABLE_IN_STOCK' => '0',
					'!PROPERTY_HIDE_UNDER_ORDER_VALUE' => 'Да',
					'!PROPERTY_SHOW_OUT_OF_STOCK_VALUE' => 'Да',
				],
			]
		];
	}

	private static function makeAllGroup(ProductCollection $collection): array
	{
		$group['ID'] = '1';
		$group['NAME'] = 'Все';
		$group['COUNT'] = $collection->getCount();
		$group['SHOW_COUNT'] = true;
		$group['PRODUCTS'] = static::makeProducts($collection);

		return $group;
	}

	private static function makePopularGroup(ProductCollection $collection): array
	{
		$group['ID'] = '1';
		$group['NAME'] = 'Все';
		$group['COUNT'] = $collection->getCount();
		$group['SHOW_COUNT'] = true;
		$group['PRODUCTS'] = $collection;

		return $group;
	}

	private static function makeProducts(ProductCollection $collection): array
	{
		$products = [];
		$withoutSort = [];

		foreach ($collection as $product) {
			if ($product->getAccessorSort()) {
				$products[] = $product;
				continue;
			}
			$withoutSort[] = $product;
		}

		uasort($products, static function ($a, $b) {
			/** @var Product $a */
			/** @var Product $b */
			return $a->getAccessorSort() >= $b->getAccessorSort();
		});

		foreach ($withoutSort as $product) {
			$products[] = $product;
		}

		return array_values($products);
	}

	/**
	 * @param list<int> $groupIds
	 * @param list<string> $select
	 * @return Collection<array>
	 */
	private static function getActiveGroups(array $groupIds, int $iblockId, array $select): Collection
	{
		$filter = [
			'ACTIVE' => 'Y',
		];
		$dbGroups = IblockHelper::forIblock($iblockId)->getElementsByIds($groupIds, $select, [], $filter);
		$sortedDbGroups = [];
		foreach ($groupIds as $groupId) {
			if (!isset($dbGroups[$groupId])) {
				continue;
			}

			$sortedDbGroups[$groupId] = $dbGroups[$groupId];
		}

		return Collection::make($sortedDbGroups);
	}

	/**
	 * @param list<int> $productIds
	 * @return list<int>
	 */
	private static function getActiveProductIds(array $productIds): array
	{
		if (!$productIds) {
			return [];
		}

		$products = IblockHelper::forIblock(CATALOG_IBLOCK_ID)
			->getElementsByFilter(static::makeProductFilter($productIds), ['ID']);

		return array_column($products, 'ID');
	}

	/**
	 * @param list<int> $groupIds
	 * @param int $iblockId
	 * @return list<int>
	 */
	public static function getNonEmptyGroupIds(array $groupIds, int $iblockId): array
	{
		$select = [
			'PROPERTY_TOVARS'
		];
		$dbGroups = static::getActiveGroups($groupIds, $iblockId, $select);

		$productIds = $dbGroups
			->flatMap(static fn (array $dbGroup) => $dbGroup['PROPERTIES']['TOVARS']['VALUE'])
			->toArray();

		$activeProductIds = static::getActiveProductIds($productIds);

		return $dbGroups
			->filter(static fn (array $dbGroup) =>
				Collection::make($dbGroup['PROPERTIES']['TOVARS']['VALUE'])
					->intersect($activeProductIds)
					->isNotEmpty()
			)
			->map(static fn (array $dbGroup) => $dbGroup['ID'])
			->values()
			->toArray();
	}

	private static function getGroups(array $groupIds, int $iblockId, bool $withProductIds = false): array
	{
		$select = [
			'NAME',
			'PROPERTY_NAMES',
			'PROPERTY_TOVARS'
		];
		$dbGroups = static::getActiveGroups($groupIds, $iblockId, $select);

		$productIds = $dbGroups
			->flatMap(static fn (array $dbGroup) => $dbGroup['PROPERTIES']['TOVARS']['VALUE'])
			->toArray();

		$activeProductIds = static::getActiveProductIds($productIds);

		$groups = [];
		foreach ($dbGroups as $dbGroup) {
			$productCount = 0;
			foreach ($dbGroup['PROPERTIES']['TOVARS']['VALUE'] as $productId) {
				if (in_array($productId, $activeProductIds)) {
					$productCount++;
				}
			}

			if ($productCount) {
				$groups[$dbGroup['ID']] = [
					'ID' => $dbGroup['ID'],
					'NAME' => trim($dbGroup['PROPERTIES']['NAMES']['VALUE']) ?: trim($dbGroup['NAME']),
					'COUNT' => $productCount,
					'SHOW_COUNT' => true,
				];

				if ($withProductIds) {
					$groups[$dbGroup['ID']]['PRODUCT_IDS'] = $dbGroup['PROPERTIES']['TOVARS']['VALUE'];
				}
			}
		}

		return $groups;
	}

	private static function getTargetProduct(int $id): array
	{
		$select = [
			'PROPERTY_ACSESS_NEW',
			'PROPERTY_ZONDS_NEW',
		];
		$product = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementById($id, $select);
		if (!$product) {
			throw new \Exception('Товар не найден.');
		}
		return $product;
	}

	private static function getTargetProducts(array $ids): array
	{
		$select = [
			'ID',
			'PROPERTY_ACSESS_NEW',
			'PROPERTY_ZONDS_NEW',
		];
		$products = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementsByIds($ids, $select);
		if (!$products) {
			throw new \Exception('Товары не найдены.');
		}
		return $products;
	}

	/**
	 * @param array $productIds
	 * @return array{accessoryId: string, array{productId: string}} ['1234' => ['1235', '1236']]
	 */
	private static function getAccessoryProductsMap(array $productIds): array
	{
		$accessoriesGroupIdProductIdsMap = static::getAccessorGroupIdsForProducts($productIds);

		if (!$accessoriesGroupIdProductIdsMap) {
			return [];
		}

		$accessoriesGroups = static::getGroups(
			array_keys($accessoriesGroupIdProductIdsMap),
			ACCESSORIES_IBLOCK_ID,
			true
		);

		$accessoryProductsMap = [];
		foreach ($accessoriesGroups as $accessorsGroup) {
			$accessoryProductsMap[current($accessorsGroup['PRODUCT_IDS'])] = $accessoriesGroupIdProductIdsMap[$accessorsGroup['ID']];
		}

		return $accessoryProductsMap;
	}

	public static function getPreviewAccessorsForProducts(array $productIds): array
	{
		if (!$productIds) {
			return [];
		}

		$accessoryProductsMap = static::getAccessoryProductsMap($productIds);

		$accessoryIds = array_keys($accessoryProductsMap);

		$accessories = Catalog::query(static::getParamsByProductIds($accessoryIds, false))->getProducts();

		$previewAccessoriesMap = [];
		foreach ($accessoryProductsMap as $accessoryId => $productIds) {
			foreach ($productIds as $productId) {
				if ($accessory = $accessories->getById($accessoryId)) {
					$previewAccessoriesMap[$productId][] = $accessory;
				}
			}
		}

		return array_map(fn(string $productId, array $accessories): array => [
			'productId' => $productId,
			'accessories' => $accessories
		], array_keys($previewAccessoriesMap), $previewAccessoriesMap);
	}

	/**
	 * @return array
	 * @throws Exception
	 */
	public static function getAccessorsForProduct(
		int $productId,
		bool $availableToBuy,
		bool $availabilityByBranch = false
	): array {
		$accessorsGroupIds = static::getAccessorGroupIdsForProduct($productId);

		if (!$accessorsGroupIds) {
			return [];
		}

		$accessorsGroups = static::getGroups(
			$accessorsGroupIds,
			ACCESSORIES_IBLOCK_ID,
			true
		);

		$productIds = [];
		foreach (array_column($accessorsGroups, 'PRODUCT_IDS') as $productIdsTmp) {
			$productIds = array_unique(array_merge($productIds, $productIdsTmp));
		}

		$productCollection = Catalog::query(static::getParamsByProductIds($productIds))
			->getProducts()
			->resizeForAccessorsTab();

		$availabilityGroups = static::getGroupedByAvailabilityProducts($productCollection, $availabilityByBranch);

		$accessorsGroups = static::fillAvailabilityForAccessorGroups(
			$accessorsGroups,
			$productCollection,
			$availabilityGroups,
			!$availableToBuy,
		);

		return static::sortProductsByAvailabilityInGroups($accessorsGroups);
	}

	private static function fillAvailabilityForAccessorGroups(
		array $accessorsGroups,
		ProductCollection $productCollection,
		array $availabilityGroups,
		bool $showNotInStock,
	): array {
		$tabIndex = 0;
		foreach ($accessorsGroups as $key => $accessorsGroup) {
			$params = [
				'REQUEST_SORT_BY' => 'default',
				'REQUEST_ORDER_BY' => '',
				'IDS_TO_SORT' => $accessorsGroup['PRODUCT_IDS'],
				'UF_USE_SALE_STATISTICS_WITH_STORE_AVAILABLE' => 1,
				'UF_USE_RESTS' => true,
				'FILTER_NAME' => 'ACCESSORY_FILTER_' . $key,
			];
			Sort::reInit($params);
			$sort = Sort::getCurrentSort();

			$availability = [
				'inStock' => [],
				'soon' => [],
				'notInStock' => [],
			];

			$inStockIndex = 0;
			$soonIndex = 0;
			$notInStockIndex = 0;

			foreach ($sort['ID'] as $productId) {
				$product = $productCollection->getById($productId);
				if (!$product) {
					continue;
				}

				if (in_array($productId, $availabilityGroups['inStock'])) {
					$availability['inStock'][] = [
						'index' => $inStockIndex++,
						'product' => $product,
						'tabIndex' => $tabIndex,
					];
				} elseif (in_array($productId, $availabilityGroups['soon'])) {
					$availability['soon'][] = [
						'index' => $soonIndex++,
						'product' => $product,
						'tabIndex' => $tabIndex,
					];
				} elseif ($showNotInStock) {
					$availability['notInStock'][] = [
						'index' => $notInStockIndex++,
						'product' => $product,
						'tabIndex' => $tabIndex,
					];
				}
			}

			$accessorsGroups[$key]['availability'] = $availability;
			$tabIndex++;
		}

		return $accessorsGroups;
	}

	private static function sortProductsByAvailabilityInGroups(array $accessorsGroups): array
	{
		$popularGroup = new ProductCollection([]);

		foreach (['inStock', 'soon', 'notInStock'] as $categoryName) {
			$categoryItems = [];
			foreach ($accessorsGroups as $key => $accessorsGroup) {
				$categoryProducts = $accessorsGroup['availability'][$categoryName] ?? [];
				if ($categoryProducts) {
					$categoryItems = array_merge($categoryItems, $categoryProducts);
					$accessorsGroups[$key]['PRODUCTS'] = array_merge(
						$accessorsGroup['PRODUCTS'] ?? [],
						array_column($categoryProducts, 'product')
					);
				}
			}

			if (!$categoryItems) {
				continue;
			}

			usort($categoryItems, static function(array $a, array $b) {
				if ($a['index'] !== $b['index']) {
					return $a['index'] <=> $b['index'];
				}

				return $a['tabIndex'] <=> $b['tabIndex'];
			});

			$popularGroup->addCollection(
				new ProductCollection(array_column($categoryItems, 'product'))
			);
		}

		foreach ($accessorsGroups as $key => $accessorsGroup) {
			$accessorsGroup[$key]['COUNT'] = count($accessorsGroup['PRODUCTS'] ?? []);
			unset($accessorsGroups[$key]['PRODUCT_IDS']);
			unset($accessorsGroups[$key]['availability']);
		}

		$allGroup = static::makePopularGroup($popularGroup);
		array_unshift($accessorsGroups, $allGroup);

		return $accessorsGroups;
	}

	private static function getGroupedByAvailabilityProducts(ProductCollection $products, bool $availabilityByBranch): array
	{
		$conditionHelper = new SortConditionHelper();
		$valuableStoreIds = $availabilityByBranch ?
			array_keys(
				StoreService::getInstance()->getByCityId(
					BranchCityService::getInstance()->getCurrentCity()->getId()
				)
			)
			: SortHelpTable::getValuableStoreIds();

		$sortFilter = [
			'PRODUCT_ID' => $products->getIds(),
			'!PRICE' => false,
		];

		$order = [
			'BY_REQUEST' => 'asc', // По запросу
			'STORE_AVAILABLE' => 'desc', // По всем складам, кроме поставщика
			'PROVIDER_STORE_AVAILABLE' => 'desc', // У поставщика
			'DEALER_TRANSIT' => 'desc', // Ожидается в транзите от поставщика, для товаров не в наличии
		];

		$runtime = [
			new Fields\ExpressionField('TOTAL_AVAILABLE', $conditionHelper->getRestExpression($valuableStoreIds)),
			new Fields\ExpressionField('PROVIDER_STORE_AVAILABLE', $conditionHelper->getProviderRestExpression($valuableStoreIds)),
			new Fields\ExpressionField('DEALER_TRANSIT', $conditionHelper->getDealerTransitExpression($valuableStoreIds)),
			new ReferenceField(
				'STATISTICS',
				StatisticsTable::class,
				['=this.PRODUCT_ID' => 'ref.PRODUCT_ID'],
				['join_type' => 'LEFT']
			)
		];

		$result = SortHelpTable::getList([
			'select' => [
				'PRODUCT_ID',
				'STORE_AVAILABLE',
				'PROVIDER_STORE_AVAILABLE',
			],
			'filter' => $sortFilter,
			'group' => ['PRODUCT_ID'],
			'order' => $order,
			'runtime' => $runtime,
		])->fetchAll();

		$groups = [
			'inStock' => [],
			'soon' => [],
			'notInStock' => [],
		];
		foreach ($result as $product) {
			if ($product['STORE_AVAILABLE'] || $product['PROVIDER_STORE_AVAILABLE']) {
				$groups['inStock'][] = (int)$product['PRODUCT_ID'];
			} elseif ($product['DEALER_TRANSIT']) {
				$groups['soon'][] = (int)$product['PRODUCT_ID'];
			} else {
				$groups['notInStock'][] = (int)$product['PRODUCT_ID'];
			}
		}

		return $groups;
	}

	public static function getZondsForProduct(int $productId, bool $availableToBuy = false): array
	{
		$zondsGroupIds = static::getZondGroupIdsForProduct($productId);

		if (!$zondsGroupIds) {
			return [];
		}

		$zondGroups = static::getGroups(
			$zondsGroupIds,
			ZONDS_IBLOCK_ID,
			true
		);

		$productIds = [];
		foreach (array_column($zondGroups, 'PRODUCT_IDS') as $productIdsTmp) {
			$productIds = array_unique(array_merge($productIds, $productIdsTmp));
		}

		$sortedProducts = static::getSortedProductsByIds($productIds, $availableToBuy);

		return static::sortProductsByGroups($zondGroups, $sortedProducts);
	}

	public static function productHasAccessories(int $productId): bool
	{
		$accessorGroupIds = static::getAccessorGroupIdsForProduct($productId);

		if (!$accessorGroupIds) {
			return false;
		}

		$accessorsGroups = static::getGroups($accessorGroupIds, ACCESSORIES_IBLOCK_ID, true);
		$productIds = [];

		foreach (array_column($accessorsGroups, 'PRODUCT_IDS') as $productIdsTmp) {
			$productIds = array_unique(array_merge($productIds, $productIdsTmp));
		}

		$inStockProductIds = Availability::getInStockProductIds($productIds);

		if (!$inStockProductIds) {
			return false;
		}

		return Catalog::getCountByFilter(['ID' => array_keys($inStockProductIds)]) > 0;
	}

	protected static function getSortedProductsByIds(array $productIds, bool $availableToBuy = false): ProductCollection
	{
		$productCollection = Catalog::query(static::getParamsByProductIds($productIds))->getProducts();

		$conditionHelper = new SortConditionHelper();
		$valuableStoreIds = SortHelpTable::getValuableStoreIds();

		$sortFilter = [
			'PRODUCT_ID' => $productCollection->getIds(),
			'!PRICE' => false,
		];

		$order = [
			'BY_REQUEST' => 'asc', // По запросу
			'STORE_AVAILABLE' => 'desc', // По всем складам, кроме поставщика
			'PROVIDER_STORE_AVAILABLE' => 'desc', // У поставщика
			'DEALER_TRANSIT' => 'desc', // Ожидается в транзите от поставщика, для товаров не в наличии
			'POPULAR_COEFFICIENT' => 'desc',
			'PRICE' => 'asc',
		];

		$runtime = [
			new Fields\ExpressionField('TOTAL_AVAILABLE', $conditionHelper->getRestExpression($valuableStoreIds)),
			new Fields\ExpressionField('PROVIDER_STORE_AVAILABLE', $conditionHelper->getProviderRestExpression($valuableStoreIds)),
			new Fields\ExpressionField('DEALER_TRANSIT', $conditionHelper->getDealerTransitExpression($valuableStoreIds)),
			new ReferenceField(
				'STATISTICS',
				StatisticsTable::class,
				['=this.PRODUCT_ID' => 'ref.PRODUCT_ID'],
				['join_type' => 'LEFT']
			)
		];

		$result = SortHelpTable::getList([
			'select' => [
				'PRODUCT_ID',
				'STORE_AVAILABLE',
				'PROVIDER_STORE_AVAILABLE',
				'BY_REQUEST',
			],
			'filter' => $sortFilter,
			'group' => ['PRODUCT_ID'],
			'order' => $order,
			'runtime' => $runtime,
		])->fetchAll();

		$sortedProducts = new ProductCollection();
		foreach ($result as $product) {
			if (!!$product['STORE_AVAILABLE'] || !!$product['PROVIDER_STORE_AVAILABLE'] || !$availableToBuy) {
				$sortedProducts->add($productCollection->getById($product['PRODUCT_ID']));
			}
		}

		return $sortedProducts->resizeForAccessorsTab();
	}

	protected static function sortProductsByGroups(
		array $groups,
		ProductCollection $products,
		bool $forDetail = false
	): array {
		$allGroupProducts = $forDetail ? new ProductCollection([]) : $products;
		foreach ($groups as $key => $accessorsGroup) {
			if (!$accessorsGroup['PRODUCT_IDS']) {
				continue;
			}

			$currentGroupTop = new ProductCollection([]);
			$idsForPopular = [];
			if ($forDetail) {
				//Нужно получить отсортированные id товаров
				$params = [
					'REQUEST_SORT_BY' => 'default',
					'REQUEST_ORDER_BY' => '',
					'IDS_TO_SORT' => $accessorsGroup['PRODUCT_IDS'],
					'UF_USE_SALE_STATISTICS_WITH_STORE_AVAILABLE' => 1,
					'UF_USE_RESTS' => true,
					'FILTER_NAME' => 'ACCESSORY_FILTER_' . $key,
				];
				Sort::reInit($params);
				$sort = Sort::getCurrentSort();
				$idsForPopular = array_slice($sort['ID'], 0 , 3);
			}
			/** @var Product $product */
			foreach ($products as $product) {
				if (in_array($product->getId(), $accessorsGroup['PRODUCT_IDS'])) {
					$groups[$key]['PRODUCTS'][] = $product;
					if (
						$product->inStock()
						&& in_array($product->getId(), $idsForPopular)
						&& !$allGroupProducts->getById($product->getId())
					) {
						$currentGroupTop->add($product);
					}
				}
			}
			if ($forDetail && !$currentGroupTop->isEmpty()) {
				//пересортируем в соответствии с порядком
				$sortedProducts = new ProductCollection([]);
				foreach ($idsForPopular as $idForPopular) {
					if (!$currentProduct = $currentGroupTop->getById($idForPopular)) {
						continue;
					}

					$sortedProducts->add($currentProduct);
				}

				$allGroupProducts->addCollection($sortedProducts);

				$currentGroupTop = null;
				$sortedProducts = null;
			}
			$groups[$key]['COUNT'] = count($groups[$key]['PRODUCTS'] ?? []);
			unset($groups[$key]['PRODUCT_IDS']);
		}
		$allGroup = static::makeAllGroup($allGroupProducts);
		array_unshift($groups, $allGroup);

		return $groups;
	}
}