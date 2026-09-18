<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Iblock\Elements\ElementCatalogTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\SystemException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Rusgeocom\Core\Utils\PriceUtils;
use Rusgeocom\Rusgeocom\Catalog\Availability;
use Rusgeocom\Rusgeocom\Catalog\Catalog;
use Rusgeocom\Rusgeocom\Catalog\Entities\ComplectConfigurator;
use Rusgeocom\Rusgeocom\Catalog\Entities\ComplectGroup;
use Rusgeocom\Rusgeocom\Catalog\Entities\ComplectGroupItem;
use Rusgeocom\Rusgeocom\Catalog\Entities\ComplectProduct;
use Rusgeocom\Rusgeocom\Catalog\Entities\Product;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductStocks;
use Rusgeocom\Rusgeocom\Catalog\Price as CatalogPrice;
use Rusgeocom\Rusgeocom\Geoip\BranchCity;
use Rusgeocom\Rusgeocom\Sale\Entities\BasketComplectPart;
use Rusgeocom\Rusgeocom\Sale\Entities\Product as SaleProduct;
use Rusgeocom\Rusgeocom\Ui\Localizer;
use Rusgeocom\Rusgeocom\Utils\HlBlockHelperRegistry;
use Rusgeocom\Rusgeocom\Utils\User;

final class ComplectService
{
	public const string GROUPS_HLBLOCK_CODE = 'ComplectGroups';

	private static array $productGroupsCache = [];

	/**
	 * @param SaleProduct $mainProduct
	 * @param BasketComplectPart[] $selectedParts
	 * @return bool
	 */
	public static function validate(SaleProduct $mainProduct, array $selectedParts): bool
	{
		$allowedGroups = self::getAllowedGroupsForProductCached($mainProduct);
		if (!$allowedGroups) {
			return false;
		}

		$mappedSelection = Arr::mapWithKeys(
			$selectedParts,
			static fn(BasketComplectPart $complectPart) => [$complectPart->getGroupId() => $complectPart]
		);

		$productsByGroup = [];
		foreach ($allowedGroups as $allowedGroup) {
			/** @var ComplectGroup $group */
			$group = $allowedGroup['group'];
			/** @var BasketComplectPart|null $selectedProduct */
			$selectedProduct = $mappedSelection[$group->getId()] ?? null;
			$code = $selectedProduct
				? self::makeComplectProductCode(
					$mainProduct->getId(),
					$selectedProduct->getProduct()->getId(),
					$group->getId(),
					$selectedProduct->getQuantity(),
				)
				: null;
			$allowedProduct = $selectedProduct ? ($allowedGroup['products'][$code] ?? null) : null;
			if ($group->isRequired() && !$allowedProduct) {
				return false;
			}

			$productsByGroup[$group->getId()] = $allowedProduct;
			if (isset($mappedSelection[$group->getId()])) {
				unset($mappedSelection[$group->getId()]);
			}
		}

		if (!$productsByGroup || count($mappedSelection) > 0 || count($productsByGroup) !== count($allowedGroups)) {
			return false;
		}

		return true;
	}

	public static function forProduct(
		Product $product,
		array $selectedProducts = [],
		?Localizer $localizer = null,
		?BranchCity $branchCity = null,
		bool $useAllGroups = false,
	): ?ComplectConfigurator {
		$allowedGroups = self::getAllowedGroupsForProductCached($product, $branchCity, $localizer);
		if (!$allowedGroups) {
			return null;
		}

		$mappedSelection = self::makeValidPartSelection($allowedGroups, $selectedProducts, $useAllGroups);

		return new ComplectConfigurator(
			mainProduct: $product,
			groupsProducts: Collection::make($allowedGroups),
			selectedProductCodes: Collection::make($mappedSelection),
			productAdvantages: self::makeProductAdvantages($allowedGroups),
			localizer: $localizer,
			forAuthorized: User::isAuthorized(),
		);
	}

	/**
	 * @param array $allowedGroups
	 * @return Collection<int, string[]>
	 */
	private static function makeProductAdvantages(array $allowedGroups): Collection
	{
		if (!$allowedGroups) {
			return Collection::empty();
		}

		$productIds = [];
		foreach ($allowedGroups as $allowedGroup) {
			$productIds = array_merge(
				$productIds,
				array_map(
					static fn(ComplectProduct $product): int => $product->getId(),
					$allowedGroup['products']
				)
			);
		}

		if (!$productIds) {
			return Collection::empty();
		}

		$iterator = ElementCatalogTable::query()
			->addSelect('ID')
			->addSelect('ADVANTAGES_LIST.VALUE', 'ADVANTAGES_LIST_VALUE')
			->whereIn('ID', $productIds)
			->exec();

		$mappedAdvantages = [];
		while ($item = $iterator->fetch()) {
			if (!$advantage = $item['ADVANTAGES_LIST_VALUE']) {
				continue;
			}

			$mappedAdvantages[(int)$item['ID']][] = rtrim($advantage, ';') . ';';
		}

		return Collection::make($mappedAdvantages);
	}

	private static function makeValidPartSelection(
		array $allowedGroups,
		array $selectedProducts,
		bool $useAllGroups = false,
	): array {
		$mappedSelection = Arr::mapWithKeys(
			$selectedProducts,
			static fn(array $selection) => [$selection['groupId'] => $selection['productCode']]
		);

		foreach ($allowedGroups as $allowedGroup) {
			/** @var ComplectGroup $group */
			$group = $allowedGroup['group'];
			$selectedProductCode = $mappedSelection[$group->getId()] ?? 0;
			/** @var ComplectProduct|null $selectedProduct */
			$selectedProduct = $selectedProductCode ? ($allowedGroup['products'][$selectedProductCode] ?? null) : null;
			if (!$selectedProduct && (($useAllGroups && $group->isActiveByDefault()) || $group->isRequired())) {
				$selectedProduct = current($allowedGroup['products']);
				$mappedSelection[$group->getId()] = $selectedProduct->getCode();
			}
		}

		return $mappedSelection;
	}

	/**
	 * @param Product|SaleProduct $product
	 * @param BranchCity|null $branchCity
	 * @param Localizer|null $localizer
	 * @return array<array{group: ComplectGroup, products: ComplectProduct[]}>|null
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public static function getAllowedGroupsForProductCached(
		Product|SaleProduct $product,
		?BranchCity $branchCity = null,
		?Localizer $localizer = null,
	): ?array {
		$cacheKey = $product->getId() . '-' . ($branchCity?->getId() ?? 0);

		if (!array_key_exists($cacheKey, self::$productGroupsCache)) {
			self::$productGroupsCache[$cacheKey] = self::getAllowedGroupsForProduct($product, $branchCity, $localizer);
		}

		return self::$productGroupsCache[$cacheKey];
	}

	/**
	 * @param Product|SaleProduct $product
	 * @param BranchCity|null $branchCity
	 * @param Localizer|null $localizer
	 * @return array<array{group: ComplectGroup, products: ComplectProduct[]}>|null
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	public static function getAllowedGroupsForProduct(
		Product|SaleProduct $product,
		?BranchCity $branchCity = null,
		?Localizer $localizer = null,
	): ?array {
		if (!$product->getPrice() || !$product->inStock()) {
			return null;
		}

		$productGroups = self::getGroupsByProductIds([$product->getId()])->first();
		if (!$productGroups) {
			return null;
		}

		usort($productGroups, static function (ComplectGroup $a, ComplectGroup $b): int {
			return $a->getRowId() <=> $b->getRowId();
		});

		$availableGroupsItems = self::getAvailableItemsByGroups($productGroups);
		if (!$availableGroupsItems) {
			return null;
		}

		$allowedProductIds = [];
		foreach ($availableGroupsItems as $availableGroupItems) {
			$availableProductIds = array_map(
				static fn(ComplectGroupItem $item): int => $item->getProductId(),
				$availableGroupItems
			);
			$allowedProductIds = array_merge($allowedProductIds, $availableProductIds);
		}

		$products = Catalog::getProductsByIds(array_unique($allowedProductIds));

		$productStocks = Availability::calculateStocksForProducts($products->getIds(), branch: $branchCity);
		$transits = Availability::getTransitsForProducts($products->getIds());

		$allowedGroups = [];
		foreach ($productGroups as $productGroup) {
			$currentGroup = [
				'group' => $productGroup,
				'products' => [],
			];

			$groupItems = $availableGroupsItems[$productGroup->getId()] ?? [];
			foreach ($groupItems as $item) {
				$productId = $item->getProductId();

				// сам основной товар не может быть в группе
				if ($productId === $product->getId()) {
					continue;
				}

				$currentProduct = $products->getById($productId);
				if ($currentProduct?->getPrice()) {
					$complectProduct = self::makeCatalogProduct(
						$product,
						$currentProduct,
						$productGroup,
						$item->getQuantity(),
						self::calculateSortIndex(
							$currentProduct,
							$item->getQuantity(),
							$item->getIndex(),
							$productStocks,
							$transits,
						),
						$localizer,
					);
					$currentGroup['products'][$complectProduct->getCode()] = $complectProduct;
				}
			}

			uasort($currentGroup['products'], function (ComplectProduct $a, ComplectProduct $b) {
				return $a->getSort() <=> $b->getSort();
			});

			if ($productGroup->isRequired() && !$currentGroup['products']) {
				return null;
			} elseif (!$currentGroup['products']) {
				continue;
			}

			$allowedGroups[] = $currentGroup;
		}

		return $allowedGroups;
	}

	private static function makeCatalogProduct(
		Product|SaleProduct $mainComplectProduct,
		Product $product,
		ComplectGroup $group,
		int $quantity = 1,
		int $sort = 0,
		?Localizer $localizer = null,
	): ComplectProduct {
		$complectPrice = CatalogPrice::discountPercent($product->getCurrentPrice(), $group->getGroupDiscount());
		$oldPrice = $product->getOldPrice() ?: $product->getBasePrice();
		$discount = max(0, $oldPrice - $complectPrice);
		$baseSegmentPrice = $product
			->getCalculatedPrice()
			?->getBaseSegmentDiscount()
			?->getDiscountPrice() ?? $product->getCurrentPrice();

		return new ComplectProduct(
			id: $product->getId(),
			code: self::makeComplectProductCode(
				$mainComplectProduct->getId(),
				$product->getId(),
				$group->getId(),
				$quantity,
			),
			name: $product->getName($localizer),
			url: $product->getUrl(),
			images: $product->makeImages(),
			mainButton: $product->getMainButtonType(),
			allowCompare: $product->canCompare(),
			managerPreview: $product->isManagerPreview(),
			price: $complectPrice * $quantity,
			oldPrice: $oldPrice * $quantity,
			basePrice: $product->getBasePrice() * $quantity,
			baseSegmentPrice: CatalogPrice::discountPercent($baseSegmentPrice, $group->getGroupDiscount()) * $quantity,
			discountPercent: PriceUtils::percent($oldPrice, $discount),
			hasGreenPrice: $product->hasGreenPrice(),
			stocks: $product->getStocks(),
			wholesalePrices: $product->getWholesalePrices(),
			quantity: $quantity,
			sort: $sort,
		);
	}

	public static function makeComplectProductCode(
		int $mainProductId,
		int $productId,
		int $groupId,
		int $quantity = 1,
	): string {
		$codeParts = [
			$mainProductId,
			$groupId,
			$productId,
			$quantity,
		];

		return md5(implode('-', $codeParts));
	}

	/**
	 * @param ComplectGroup[] $productGroups
	 * @return array<int, ComplectGroupItem[]>
	 */
	private static function getAvailableItemsByGroups(array $productGroups): array
	{
		$availableGroupItems = [];
		foreach ($productGroups as $productGroup) {
			$isRequired = $productGroup->isRequired();
			$availableItems = [];
			foreach ($productGroup->getItems() as $item) {
				$availableItems[] = $item;
			}

			if ($isRequired && !$availableItems) {
				return [];
			} elseif (!$availableItems) {
				continue;
			}

			$availableGroupItems[$productGroup->getId()] = $availableItems;
		}

		return $availableGroupItems;
	}

	/**
	 * @param int[] $productIds
	 * @return Collection<int, ComplectGroup[]>
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getGroupsByProductIds(array $productIds): Collection
	{
		if (!$productIds) {
			return Collection::empty();
		}

		$iterator = ElementCatalogTable::query()
			->addSelect('ID')
			->registerRuntimeField(new ReferenceField(
					'COMPLECT_GROUP',
					HlBlockHelperRegistry::getInstance()->getByCode(self::GROUPS_HLBLOCK_CODE)->getEntityClass(),
					Join::on('this.COMPLECT_GROUP_IDS.VALUE', 'ref.UF_XML_ID')
				)
			)
			->addSelect('COMPLECT_GROUP_IDS.ID', 'ROW_ID')
			->addSelect('COMPLECT_GROUP.ID', 'GROUP_ID')
			->addSelect('COMPLECT_GROUP.UF_PRODUCTS', 'PRODUCTS')
			->addSelect('COMPLECT_GROUP.UF_DISCOUNT', 'DISCOUNT')
			->addSelect('COMPLECT_GROUP.UF_FULL_DESCRIPTION', 'DESCRIPTION')
			->addSelect('COMPLECT_GROUP.UF_REQUIRED', 'REQUIRED')
			->addSelect('COMPLECT_GROUP.UF_ACTIVE_BY_DEFAULT', 'ACTIVE_BY_DEFAULT')
			->whereIn('ID', $productIds)
			->where('SHOW_ADVANTAGEOUS_COMPLECTS.ITEM.XML_ID', 'Y')
			->addOrder('COMPLECT_GROUP_IDS.ID')
			->exec();

		$groups = [];
		$existingGroupIds = [];
		while ($row = $iterator->fetch()) {
			$groupId = (int)$row['GROUP_ID'];
			if (in_array($groupId, $existingGroupIds)) {
				continue;
			}

			$items = Collection::empty();
			foreach ($row['PRODUCTS'] as $index => $product) {
				$parts = explode('|', $product);
				$productId = (int)$parts[0];
				$quantity = isset($parts[1]) ? (int)$parts[1] : 1;
				$key = "{$productId}-{$quantity}";
				if (!$items->has($key)) {
					$items->put($key, new ComplectGroupItem($index, $productId, $quantity));
				}
			}

			$groups[(int)$row['ID']][] = new ComplectGroup(
				id: $groupId,
				rowId: (int)$row['ROW_ID'],
				description: $row['DESCRIPTION'] ?? '',
				isRequired: (bool)$row['REQUIRED'],
				groupDiscount: (int)$row['DISCOUNT'],
				items: $items,
				activeByDefault: $row['ACTIVE_BY_DEFAULT'] === null || $row['ACTIVE_BY_DEFAULT'],
			);
			$existingGroupIds[] = $groupId;
		}

		return Collection::make($groups);
	}

	/**
	 * @param Product $product
	 * @param int $quantity
	 * @param int $index
	 * @param ProductStocks[] $productStocks
	 * @param array $transits
	 * @return int
	 */
	private static function calculateSortIndex(
		Product $product,
		int $quantity,
		int $index,
		array $productStocks,
		array $transits,
	): int {
		$startGroupIndex = 10000; //нет в наличии

		$currentStocks = $productStocks[$product->getId()] ?? null;
		$currentTransits = array_sum($transits[$product->getId()] ?? []);
		if ($currentStocks->getSumAmountWithoutDlrStore() >= $quantity) {
			$startGroupIndex = 0; // в наличии на складах
		} elseif ($currentStocks->getSumAmount() >= $quantity) {
			$startGroupIndex = 1000; // со складом поставщика
		} elseif (($currentTransits + $currentStocks->getSumAmount()) >= $quantity) {
			$startGroupIndex = 2000; // c транзитом
		}

		return $startGroupIndex + $index;
	}
}
