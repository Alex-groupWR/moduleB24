<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class ProductStocks
{
	private $stocks = [];

	public function __construct(array $stocks)
	{
		$this->stocks = $stocks;
	}

	public function getStoreAmount(int $storeId): int
	{
		return $this->stocks[$storeId] ?: 0;
	}

	public function isProductAvailable(): int
	{
		$hasNegativeStock = false;

		foreach ($this->stocks as $amount){
			if ($amount > 0){
				return 1;
			} else {
				if ($amount < 0) {
					$hasNegativeStock = true;
				}
			}
		}

		return $hasNegativeStock ? -1 : 0;
	}

	public function getSumAmount(): int
	{
		$sumAmount = 0;
		foreach ($this->stocks as $amount){
			if ($amount > 0){
				$sumAmount += (int)$amount;
			}
		}

		return $sumAmount;
	}

	public function getSumAmountWithoutDlrStore(): int
	{

		$sumAmount = 0;
		foreach ($this->stocks as $storeId => $amount){
			if ($storeId === DLR_STORE_ID) {
				continue;
			}
			if ($amount > 0){
				$sumAmount += (int)$amount;
			}
		}

		return $sumAmount;
	}

	/**
	 * @deprecated
	 *
	 * @return int[]
	 */
	public function getRawStocks(): array
	{
		return $this->stocks;
	}
}