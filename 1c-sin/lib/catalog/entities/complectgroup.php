<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use Illuminate\Support\Collection;
use JsonSerializable;

final readonly class ComplectGroup implements JsonSerializable
{
	/**
	 * @param int $id
	 * @param int $rowId
	 * @param string $description
	 * @param bool $isRequired
	 * @param int $groupDiscount
	 * @param Collection<string, ComplectGroupItem> $items
	 * @param bool $activeByDefault
	 */
	public function __construct(
		private int $id,
		private int $rowId,
		private string $description,
		private bool $isRequired,
		private int $groupDiscount,
		private Collection $items,
		private bool $activeByDefault = true,
	) {
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getRowId(): int
	{
		return $this->rowId;
	}

	public function getDescription(): string
	{
		return $this->description;
	}

	public function isRequired(): bool
	{
		return $this->isRequired;
	}

	public function isActiveByDefault(): bool
	{
		return $this->activeByDefault;
	}

	public function getGroupDiscount(): int
	{
		return $this->groupDiscount;
	}

	/**
	 * @return int[]
	 */
	public function getProductIds(): array
	{
		return $this->getItems()
			->map(static fn(ComplectGroupItem $item): int => $item->getProductId())
			->toArray();
	}

	/**
	 * @return Collection<string, ComplectGroupItem>
	 */
	public function getItems(): Collection
	{
		return $this->items;
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->getId(),
			'order' => $this->getRowId(),
			'description' => $this->getDescription(),
			'isRequired' => $this->isRequired(),
		];
	}
}
