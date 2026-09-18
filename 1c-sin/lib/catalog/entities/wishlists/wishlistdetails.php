<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities\Wishlists;

final readonly class WishlistDetails
{
	public function __construct(
		private int $id,
		private string $phone,
		private ?int $userId = null,
		private ?string $name = null,
		private ?string $email = null,
		private bool $emailConfirmed = false,
		/** @var WishlistProduct[] */
		private array $items = [],
		private ?string $headerCity = null,
	) {
	}

	public function getId(): int
	{
		return $this->id;
	}

	public function getPhone(): string
	{
		return $this->phone;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function getName(): ?string
	{
		return $this->name;
	}

	public function isEmailConfirmed(): bool
	{
		return $this->emailConfirmed;
	}

	public function getEmail(): ?string
	{
		return $this->email;
	}

	public function getHeaderCity(): ?string
	{
		return $this->headerCity;
	}

	/**
	 * @return WishlistProduct[]
	 */
	public function getItems(): array
	{
		return $this->items;
	}
}
