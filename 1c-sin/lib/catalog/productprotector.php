<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use CIBlockElement;
use Rusgeocom\Rusgeocom\Utils\MailService;
use Rusgeocom\Rusgeocom\Utils\User;

final class ProductProtector
{
	private const string NOTIFICATION_EMAIL = 'bva@rusgeocom.ru';

	public static function bindEvents(): void
	{
		$eventManager = EventManager::getInstance();
		$eventManager->addEventHandlerCompatible(
			fromModuleId: 'iblock',
			eventType: 'OnBeforeIBlockElementDelete',
			callback: [__CLASS__, 'onBeforeElementDelete'],
			sort: 0
		);
	}

	public static function onBeforeElementDelete(int $id): bool
	{
		$iblockId = CIBlockElement::GetIBlockByID($id);
		if ($iblockId === CATALOG_IBLOCK_ID) {
			MailService::sendFormFields(
				self::NOTIFICATION_EMAIL,
				'Попытка удаления товара',
				'Попытка удаления товара',
				[
					'ID товара' => $id,
					'ID пользователя' => User::getId(),
				]
			);

			$db = Application::getConnection();
			$db->commitTransaction();
			$db->startTransaction();

			global $APPLICATION;
			$APPLICATION->ThrowException('Установлен запрет на удаление товаров');
			return false;
		}

		return true;
	}
}