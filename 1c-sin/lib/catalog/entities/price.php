<?php

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class Price implements \JsonSerializable
{
	private $value;
	private $oldValue;

	public function __construct(int $value, int $oldValue = 0)
	{
		$this->value = $value;
		$this->oldValue = $oldValue;
	}

	public function getValue(): int
	{
		return $this->value;
	}

	public function getOldValue(): int
	{
		return $this->oldValue;
	}

	public function jsonSerialize(): array
	{
		return [
			'price' => $this->value,
			'oldPrice' => $this->oldValue,
		];
	}
}