<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

final readonly class WishlistEventInfo
{
	public function __construct(
		private int $fuserId,
		private array $productIds,
		private string $domain,
		private ?int $userId = null,
		private ?string $userCheckoutPhone = null,
		private ?string $userCheckoutEmail = null,
		private ?string $cityFias = null,
		private ?string $headerCity = null,
	) {
	}

	public function getFuserId(): int
	{
		return $this->fuserId;
	}

	public function getProductIds(): array
	{
		return $this->productIds;
	}

	public function getDomain(): string
	{
		return $this->domain;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function getUserCheckoutPhone(): ?string
	{
		return $this->userCheckoutPhone;
	}

	public function getUserCheckoutEmail(): ?string
	{
		return $this->userCheckoutEmail;
	}

	public function getCityFias(): ?string
	{
		return $this->cityFias;
	}

	public function getHeaderCity(): ?string
	{
		return $this->headerCity;
	}
}
