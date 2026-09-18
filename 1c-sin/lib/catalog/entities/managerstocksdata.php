<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Rusgeocom\Rusgeocom\Stores\Store;

class ManagerStocksData implements \JsonSerializable
{
	/** @var Store */
	private $store;
	private $amount = 0;
	private $reserve = 0;
	private $transit = 0;
	private $dealerTransit = [];

	public function __construct(Store $store, int $amount, int $reserve, int $transit, array $dealerTransit)
	{
		$this->store = $store;
		$this->amount = $amount;
		$this->reserve = $reserve;
		$this->transit = $transit;
		$this->dealerTransit = $dealerTransit;
	}

	public function jsonSerialize(): array
	{
		return [
			'storage' => $this->store->getNameForManager(),
			'amount' => $this->amount,
			'reserve' => $this->reserve,
			'transit' => $this->transit,
			'dealerTransits'=> $this->dealerTransit,
			'isDealerWarehouse'=> $this->store->isDealerWarehouse(),
		];
	}
}