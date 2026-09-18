<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Fields\Relations;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Rusgeocom\Rusgeocom\Catalog\Objectify\PriceSubscription;
use Rusgeocom\Rusgeocom\Personal\Tables\UserProfilesTable;

class PriceSubscriptionTable extends Entity\DataManager
{
	public static function getTableName(): string
	{
		return 'rusgeocom_catalog_price_subscriptions';
	}

	public static function getObjectClass(): string
	{
		return PriceSubscription::class;
	}

	public static function getMap(): array
	{
		return [

			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new Fields\IntegerField('PROFILE_ID'))
				->configureNullable(),

			(new Fields\StringField('EMAIL'))
				->configureNullable(),

			(new Fields\IntegerField('PRODUCT_ID'))
				->configureRequired(),

			(new Fields\IntegerField('OLD_PRICE'))
				->configureRequired(),

			(new Fields\StringField('STATUS'))
				->configureSize(50)
				->configureRequired(),

			(new Fields\DatetimeField('DATE_INSERT'))
				->configureDefaultValue(static fn() => new DateTime()),

			(new Fields\DatetimeField('DATE_UPDATE'))
				->configureDefaultValue(static fn() => new DateTime()),

			(new Relations\Reference(
				'PROFILE',
				UserProfilesTable::class,
				Join::on('this.PROFILE_ID', 'ref.ID')
			))
				->configureJoinType('LEFT'),

			(new Relations\Reference(
				'ELEMENT',
				ElementTable::class,
				Join::on('this.PRODUCT_ID', 'ref.ID')
			))
				->configureJoinType('LEFT'),
		];
	}

	public static function onBeforeUpdate(Entity\Event $event): Entity\EventResult
	{
		$result = new Entity\EventResult;

		$result->modifyFields([
			'DATE_UPDATE' => new DateTime(),
		]);

		return $result;
	}
}