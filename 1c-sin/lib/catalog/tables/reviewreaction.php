<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity;
use Bitrix\Main\ORM\Fields;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserTable;
use Rusgeocom\Rusgeocom\Catalog\Objectify\ReviewReaction;

class ReviewReactionTable extends Entity\DataManager
{
	public static function getObjectClass(): string
	{
		return ReviewReaction::class;
	}

	public static function getTableName(): string
	{
		return 'rusgeocom_catalog_review_reactions';
	}

	public static function getMap(): array
	{
		return [

			(new Fields\IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new Fields\IntegerField('REVIEW_ID'))
				->configureRequired(),

			(new Fields\IntegerField('USER_ID'))
				->configureRequired(),

			(new Fields\StringField('REACTION'))
				->configureSize(25)
				->configureRequired(),

			(new Fields\DatetimeField('CREATED_AT'))
				->configureDefaultValue(static fn() => new DateTime()),

			(new Fields\DatetimeField('UPDATED_AT'))
				->configureDefaultValue(static fn() => new DateTime()),

			(new Reference('USER', UserTable::class, Join::on('this.USER_ID', 'ref.ID'))),
		];
	}

	public static function onBeforeUpdate(Entity\Event $event): Entity\EventResult
	{
		$result = new Entity\EventResult;

		$result->modifyFields([
			'UPDATED_AT' => new DateTime(),
		]);

		return $result;
	}
}