<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use CIBlockElement;
use Bitrix\Main\EventManager;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;

/*
 * Во имя ускорения выборки комплектов в публичке по фильтрам, было решено добавить свойство, в котором бы хранилось 1/0
 * в зависимости от соответсвия/нет стандартного набора фильтров необходимым значениям
 *
 * На событии обновления товара вычисляется значение для поля COMPLECT_BASIC_FILTER_FLAG
 *
 * Так же есть метод для массового вычисления
 */
class ComplectBasicPropertiesFlagComputer
{
	public static $isProcessing;

	public static function bindEvents(): void
	{
		EventManager::getInstance()->addEventHandler('iblock', 'OnAfterIBlockElementUpdate', [__CLASS__, 'onAfterElementUpdate']);
		EventManager::getInstance()->addEventHandler('iblock', 'OnAfterIBlockElementAdd', [__CLASS__, 'onAfterElementUpdate']);
	}

	public static function onAfterElementUpdate(array $fields): void
	{
		if ($fields['IBLOCK_ID'] != CATALOG_IBLOCK_ID || static::$isProcessing) {
			return;
		}

		static::$isProcessing = true;

		$productId = $fields['ID'];

		if (!$productId) {
			return;
		}

		$product = [];
		$iterator = IblockElementPropertyTable::query()
			->addFilter('=IBLOCK_ELEMENT_ID', $productId)
			->addFilter('=IBLOCK_ELEMENT.ACTIVE', 'Y')
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('IBLOCK_PROPERTY.CODE', 'PROPERTY_CODE')
			->addFilter('=IBLOCK_PROPERTY.CODE', [
				'COMPLECT_MAIN_PRODUCT',
				'HIDE_IN_CATALOG',
				'ARCHIVE',
				'PRICE_ON_REQUEST',
				'NO_ACTIVE_BY_SECTIONS',
				'ACSESSUAR_SORT',
				'COMPLECT_BASIC_FILTER_FLAG'
			])
			->addSelect('VALUE')
			->exec();

		while ($row = $iterator->fetch()) {
			$product[$row['PROPERTY_CODE']][] = $row['VALUE'];
		}

		if ($product && !$product['COMPLECT_MAIN_PRODUCT']) {
			return;
		}

		$newFlag = static::getNewFlagValue($product);

		if (!$product['COMPLECT_BASIC_FILTER_FLAG'] || static::isNeedUpdate($newFlag, $product['COMPLECT_BASIC_FILTER_FLAG'][0])) {
			static::updateFlagValue($productId, $newFlag);
		}

		static::$isProcessing = false;
	}

	public static function updateComplectBasicFlag(array $complects): void
	{
		if (!$complects) {
			return;
		}
		static::$isProcessing = true;

		$complectsNeedUpdate = [];
		foreach ($complects as $id=>$complect) {
			$newFlag = static::getNewFlagValue($complect);

			if (static::isNeedUpdate($newFlag, $complect['COMPLECT_BASIC_FILTER_FLAG'] ?? 0)) {
				$complectsNeedUpdate[$id] = $newFlag;
			}
		}

		foreach ($complectsNeedUpdate as $complectId=>$flag) {
			static::updateFlagValue($complectId, $flag);
		}

		static::$isProcessing = false;
	}

	private static function getNewFlagValue($product): bool
	{
		if (
			!$product
			|| $product['ACTIVE'] && $product['ACTIVE'] !== 'Y'
			|| $product['HIDE_IN_CATALOG']
			|| $product['NO_ACTIVE_BY_SECTIONS']
			|| $product['ACSESSUAR_SORT']
			|| $product['ARCHIVE']
			|| $product['PRICE_ON_REQUEST']
		) {
			$newFlag = false;
		} else {
			$newFlag = true;
		}

		return $newFlag;
	}

	private static function isNeedUpdate(bool $newFlag, bool $oldFlag): bool
	{
		return $oldFlag !== $newFlag;
	}

	private static function updateFlagValue(int $productId, bool $newFlag): void
	{
		CIBlockElement::SetPropertyValuesEx($productId, CATALOG_IBLOCK_ID, ['COMPLECT_BASIC_FILTER_FLAG' => (int)$newFlag]);
	}
}