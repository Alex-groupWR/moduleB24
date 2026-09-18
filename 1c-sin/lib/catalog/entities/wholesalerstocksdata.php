<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Rusgeocom\Rusgeocom\Stores\Store;

class WholesalerStocksData implements \JsonSerializable
{
	/** @var Store */
	private $store;
	private $amount = 0;

	public function __construct(Store $store, int $amount)
	{
		$this->store = $store;
		$this->amount = $amount;
	}

	public function jsonSerialize(): array
	{
		return [
			'storage' => $this->store->getNameForManager(),
			'amount' => $this->amount,
			'isDealerWarehouse'=> $this->store->isDealerWarehouse(),
		];
	}
}