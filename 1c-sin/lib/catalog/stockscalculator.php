<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\StoreProductTable;
use Bitrix\Main\ORM\Query\Query;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductStocks;
use Rusgeocom\Rusgeocom\Geoip\BranchCity;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;
use Rusgeocom\Rusgeocom\Stores\StoreService;

class StocksCalculator
{
	private $sourceProductIds = [];
	private $productIdToComplectUuidsMap = [];
	private $uuidToIdMap = [];
	private $allUuids = [];
	private $stocks = [];
	private $notExistedUuids = [];
	private ?BranchCity $branch;

	public function __construct(array $productIds, ?BranchCity $branch = null)
	{
		$this->sourceProductIds = $productIds;
		$this->branch = $branch;
	}

	/**
	 * @return ProductStocks[]
	 */
	public function calculate(): array
	{
		$this->fillComplectUuids();
		$this->fillComplectProducts();
		$this->fillStocks();

		$result = [];
		foreach ($this->sourceProductIds as $productId){
			$result[$productId] = $this->getMinimumStocksForProduct((int)$productId);
		}

		return $result;
	}

	private function getProductIdsByUuid(string $uuid): array
	{
		return $this->uuidToIdMap[$uuid] ?? [];
	}

	private function getComplectProductIdsForProduct(int $productId): array
	{
		$uuids = $this->productIdToComplectUuidsMap[$productId];
		$productIds = [];
		foreach ($uuids as $uuid){
			foreach ($this->getProductIdsByUuid($uuid) as $id){
				$productIds[] = $id;
			}
		}

		return $productIds;
	}

	public function getStocksByProductId(int $productId): array
	{
		return $this->stocks[$productId] ?: [];
	}

	private function getMinimumStocksForProduct(int $id): ProductStocks
	{
		// Несуществующие считаем пустыми
		foreach ($this->productIdToComplectUuidsMap[$id] as $uuid){
			if (in_array($uuid, $this->notExistedUuids)){
				$emptyStocks = [];
				foreach (StoreService::getInstance()->getIds() as $storeId){
					$emptyStocks[$storeId] = 0;
				}
				return new ProductStocks($emptyStocks);
			}
		}

		$productIds = $this->getComplectProductIdsForProduct($id);
		$productIds[] = $id;
		$productIds = array_unique($productIds);

		$result = [];
		foreach (StoreService::getInstance()->getIds() as $storeId){
			foreach ($productIds as $productId){
				$productStocks = $this->getStocksByProductId($productId);
				$amount = $productStocks[$storeId];
				if ($amount !== null && ($result[$storeId] === null || $amount < $result[$storeId])){
					$result[$storeId] = (int)$amount;
				}
			}
		}

		return new ProductStocks($result);
	}

	private function fillStocks(): void
	{
		$productIds = $this->sourceProductIds;
		foreach ($this->uuidToIdMap as $uuidIds){
			foreach ($uuidIds as $id){
				$productIds[] = $id;
			}
		}

		if (!$productIds){
			return;
		}

		$query = StoreProductTable::query()
			->addSelect('STORE_ID')
			->addSelect('PRODUCT_ID')
			->addSelect('AMOUNT');

		if ($this->branch) {
			$branchStores = StoreService::getInstance()->getByCityId($this->branch->getId());
			if ($branchStores) {
				$query->whereIn('STORE_ID', array_keys($branchStores));
			}
		}

		$iterator = $query
			->addFilter('=PRODUCT_ID', $productIds)
			->exec();
		while ($row = $iterator->fetch()){
			$this->stocks[$row['PRODUCT_ID']][$row['STORE_ID']] = $row['AMOUNT'];
		}
	}

	private function fillComplectProducts(): void
	{
		if (!$this->allUuids) {
			return;
		}

		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addSelect('IBLOCK_PROPERTY.CODE', 'PROPERTY_CODE')
			->where('IBLOCK_ELEMENT.ACTIVE', 'Y')
			->where(
				Query::filter()
					->logic('OR')
					->where('IBLOCK_PROPERTY.CODE', 'UUID_1S_MULTI')
					->whereIn('VALUE', $this->allUuids)
			)
			->whereIn('IBLOCK_PROPERTY.CODE', ['UUID_1S_MULTI', 'UT_GUID_NEW'])
			->exec();

		$productsByUt = [];
		while ($row = $iterator->fetch()) {
			$productsByUt[$row['IBLOCK_ELEMENT_ID']][$row['PROPERTY_CODE']][] = $row['VALUE'];
		}

		foreach ($productsByUt as $productId => $product) {
			// нас не интересуют другие комплекты
			if (isset($product['UUID_1S_MULTI']) || !isset($product['UT_GUID_NEW'])) {
				continue;
			}

			$this->uuidToIdMap[current($product['UT_GUID_NEW'])][] = (int)$productId;
		}

		foreach ($this->allUuids as $uuid) {
			if (!$this->uuidToIdMap[$uuid]) {
				$this->notExistedUuids[] = $uuid;
			}
		}
	}

	private function fillComplectUuids(): void
	{
		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addFilter('=IBLOCK_ELEMENT.ACTIVE', 'Y')
			->addFilter('=IBLOCK_ELEMENT_ID', $this->sourceProductIds)
			->addFilter('=IBLOCK_PROPERTY.CODE', 'UUID_1S_MULTI')
			->exec();
		while ($row = $iterator->fetch()){
			$this->productIdToComplectUuidsMap[$row['IBLOCK_ELEMENT_ID']][] = $row['VALUE'];
			$this->allUuids[] = $row['VALUE'];
		}
	}
}