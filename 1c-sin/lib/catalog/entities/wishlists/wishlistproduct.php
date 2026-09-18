<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities\Wishlists;

final readonly class WishlistProduct
{
	public function __construct(
		private int $id,
		private string $name,
		private string $url,
		private int $price,
	) {
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getPrice(): int
	{
		return $this->price;
	}

	public function getUrl(): string
	{
		return $this->url;
	}
}