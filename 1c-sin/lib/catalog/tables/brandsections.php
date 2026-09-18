<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\ORM\Fields\BooleanField;
use Bitrix\Main\ORM\Fields\IntegerField;

class BrandSectionsTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'rusgeocom_brand_sections';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('BRAND_ID'))
				->configureRequired(),

			(new IntegerField('SECTION_ID'))
				->configureRequired(),

			(new IntegerField('ORDER'))
				->configureRequired()
				->configureDefaultValue(0),

			(new BooleanField('DISPLAY_IN_SECTION_TREE'))
				->configureDefaultValue(false),
		];
	}
}