<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Auth;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

class SessionTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'rusgeocom_exchange_external_client_sessions';
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete(),

			(new DatetimeField('DATE_INSERT'))
				->configureRequired()
				->configureDefaultValue(new DateTime()),

			(new StringField('CLIENT_UUID'))
				->configureRequired()
				->configureSize(36),

			(new StringField('SESSION_UUID'))
				->configureRequired()
				->configureSize(36),
		];
	}
}