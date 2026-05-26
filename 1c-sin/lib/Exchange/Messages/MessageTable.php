<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Messages;

use Bitrix\Main\Entity\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\TextField;
use Bitrix\Main\Type\DateTime;

class MessageTable extends DataManager
{
	// new -> exchange_success -> handle_success -> удалить
	public const STATUS_NEW = 'new'; // Ожидает отправки
	public const STATUS_EXCHANGE_SUCCESS = 'exchange_success'; // Успешно отправлен, ожидает обработки ответа
	public const STATUS_EXCHANGE_FAIL = 'exchange_fail'; // Ошибка при обмене
	public const STATUS_HANDLE_SUCCESS = 'handle_success'; // Успешно обработан
	public const STATUS_HANDLE_FAIL = 'handle_fail'; // Ошибка при обработке ответа
	public const STATUS_CANCEL = 'cancel'; // Отменено/дубль

	public static function getTableName(): string
	{
		return 'rusgeocom_exchange_messages';
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

			(new DatetimeField('DATE_SENT'))
				->configureNullable(),

			(new DatetimeField('DATE_HANDLE'))
				->configureNullable(),

			(new IntegerField('TRY_COUNT'))
				->configureRequired()
				->configureDefaultValue(0),

			(new StringField('STATUS'))
				->configureRequired()
				->configureSize(16),

			// Сюда можно вписывать какой-нибудь "order-123", чтобы не отправлять повторно одну сущность
			(new StringField('SYNC_ID'))
				->configureNullable()
				->configureSize(64),

			(new TextField('RESULT'))
				->configureNullable(),

			(new TextField('RESPONSE'))
				->configureNullable(),

			(new StringField('ACTION'))
				->configureRequired()
				->configureSize(255),

			(new TextField('PAYLOAD'))
				->configureRequired(),
		];
	}
}