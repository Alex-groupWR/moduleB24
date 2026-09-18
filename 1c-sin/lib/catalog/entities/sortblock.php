<?php
namespace Rusgeocom\Rusgeocom\Catalog\Entities;

class SortBlock implements \JsonSerializable
{
	private $variants;

	public function __construct()
	{
		$this->variants = [
			['name' => 'Популярные', 'by' => 'default', 'order' => '', 'isActive' => true],
			['name' => 'Дешевле', 'by' => 'price', 'order' => 'asc', 'isActive' => false],
			['name' => 'Дороже', 'by' => 'price', 'order' => 'desc', 'isActive' => false],
		];
	}

	public function setActive(string $by, string $order = '')
	{
		foreach ($this->variants as $kVariant => $variant){
			$this->variants[$kVariant]['isActive'] = false;
		}

		foreach ($this->variants as $kVariant => $variant){
			if ($variant['by'] == $by && (!$order || $variant['order'] == $order)){
				$this->variants[$kVariant]['isActive'] = true;
				return;
			}
		}

		// Если не нашли
		$this->variants[0]['isActive'] = true;
	}

	public function getActive(): array
	{
		foreach ($this->variants as $variant){
			if ($variant['isActive']){
				return $variant;
			}
		}

		throw new \Exception('Не найден активный тип сортировки');
	}

	public function jsonSerialize()
	{
		return $this->variants;
	}
}