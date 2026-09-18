<?php

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

class PriceRecalculationsTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'rusgeocom_price_recalculations';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('IBLOCK_ELEMENT_ID'))
				->configureRequired(),

			(new IntegerField('IBLOCK_ID'))
				->configureRequired(),

			(new IntegerField('CATALOG_GROUP_ID'))
				->configureRequired(),

			(new StringField('DISCOUNT_VALUE'))
				->configureRequired(),

			(new DatetimeField('DATE_CREATED'))
				->configureRequired()
				->configureDefaultValue(new DateTime()),
		];
	}
}