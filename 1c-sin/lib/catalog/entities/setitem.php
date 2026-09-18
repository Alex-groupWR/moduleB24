<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

use JsonSerializable;
use Rusgeocom\Rusgeocom\Types\Image;

final readonly class SetItem implements JsonSerializable
{
	public function __construct(
		private string $name,
		private ?int $count = null,
		private ?string $url = null,
		private ?Image $image = null,
	) {
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getCount(): ?int
	{
		return $this->count;
	}

	public function getUrl(): ?string
	{
		return $this->url;
	}

	public function getImage(): ?Image
	{
		return $this->image?->resize([800], [78]);
	}

	public function getFormattedLabel(): string
	{
		$count = $this->getCount();
		return $this->name . ($count !== null ? ' - ' . $count . 'шт.' : '');
	}

	public function jsonSerialize(): array
	{
		return [
			'name' => $this->getFormattedLabel(),
			'url' => $this->getUrl(),
			'image' => $this->getImage(),
		];
	}
}
