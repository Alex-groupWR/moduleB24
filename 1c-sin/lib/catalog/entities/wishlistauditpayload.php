<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use JsonSerializable;

final readonly class WishlistAuditPayload implements JsonSerializable
{
	public function __construct(
		private int $fuserId,
		private int $userId,
		private array $productIds = [],
		private array $contactInfo = [],
		private string $cityFias = '',
		private string $domain = '',
		private string $headerCity = '',
	) {
	}

	public function getFuserId(): int
	{
		return $this->fuserId;
	}

	public function getUserId(): int
	{
		return $this->userId;
	}

	public function getProductIds(): array
	{
		return $this->productIds;
	}

	public function getContactInfo(): array
	{
		return $this->contactInfo;
	}

	public function getCityFias(): string
	{
		return $this->cityFias;
	}

	public function getDomain(): string
	{
		return $this->domain;
	}

	public function getHeaderCity(): string
	{
		return $this->headerCity;
	}

	public static function fromArray(array $payload): self
	{
		return new self(
			fuserId: (int)$payload['fuserId'],
			userId: (int)$payload['userId'],
			productIds: $payload['productIds'] ?? [],
			contactInfo: $payload['contact'] ?? [],
			cityFias: $payload['cityFias'] ?? '',
			domain: $payload['domain'] ?? '',
			headerCity: $payload['headerCity'] ?? ''
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'fuserId' => $this->getFuserId(),
			'userId' => $this->getUserId(),
			'productIds' => $this->getProductIds(),
			'contactInfo' => $this->getContactInfo(),
			'cityFias' => $this->getCityFias(),
			'domain' => $this->getDomain(),
			'headerCity' => $this->getHeaderCity(),
		];
	}
}
