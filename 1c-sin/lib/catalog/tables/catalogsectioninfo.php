<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

class CatalogSectionInfoTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'rusgeocom_catalog_section_info';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('IBLOCK_SECTION_ID'))
				->configureRequired(),

			(new IntegerField('BRANDS_COUNT_WITH_SUBSECTIONS'))
				->configureRequired(),
		];
	}
}