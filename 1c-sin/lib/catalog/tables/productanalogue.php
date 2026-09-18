<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Fields;

class ProductAnalogueTable extends Entity\DataManager
{
	public static function getTableName(): string
	{
		return 'rusgeocom_catalog_product_analogues';
	}

	public static function getMap(): array
	{
		return [

			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new Fields\IntegerField('PRODUCT_ID'))
				->configureRequired(),

			(new Fields\IntegerField('ANALOGUE_ID'))
				->configureRequired(),

			(new Fields\BooleanField('STATIC'))
				->configureDefaultValue(false),

		];
	}
}