<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Enums;

enum CatalogViewType: string
{
	case List = 'list';
	case Tiles = 'tiles';

	public function getName(): string
	{
		return match ($this) {
			CatalogViewType::Tiles => 'Плитка',
			CatalogViewType::List => 'Список',
		};
	}
}
