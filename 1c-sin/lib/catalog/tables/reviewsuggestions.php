<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity\BooleanField;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\Type\DateTime;

class ReviewSuggestionsTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'rusgeocom_review_suggestions';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new IntegerField('USER_ID'))
				->configureRequired(),

			new IntegerField('PROFILE_ID'),

			(new IntegerField('PRODUCT_ID'))
				->configureRequired(),

			(new IntegerField('ORDER_ID'))
				->configureRequired(),

			(new BooleanField('ACTIVE'))
				->configureDefaultValue(true),

			(new DatetimeField('DATE_CREATE'))
				->configureDefaultValue(new DateTime()),
		];
	}
}