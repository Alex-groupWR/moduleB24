<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\PriceTable;
use Bitrix\Iblock\ElementTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\EventManager;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Datetime;
use Illuminate\Support\Arr;
use Logema\Utils\DataAccess\HighloadblockHelper;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;
use Rusgeocom\Rusgeocom\Utils\User;

/**
 * Запись изменений цены в HighLoad - блок по событиям добавления, изменения и удаления цены
 */
class PriceHistory
{
	private static array $oldPrices = [];
	private static array $priceIdToProduct = [];
	private static int $priceOnRequestPropertyId = 0;
	private static array $priceOnRequest = [];
	private static array $productByOffer = [];
	private static array $productIblockIds = [];
	private static ?int $processingProductId = null;

	public static function bindEvents(): void
	{
		$eventManager = EventManager::getInstance();
		$eventManager->addEventHandlerCompatible(
			'catalog',
			'OnBeforePriceUpdate',
			[__CLASS__, 'preparePriceChangesToHLBlock']
		);
		$eventManager->addEventHandlerCompatible(
			'catalog',
			'OnPriceAdd',
			[__CLASS__, 'writePriceChangesToHLBlock']
		);
		$eventManager->addEventHandlerCompatible(
			'catalog',
			'OnPriceUpdate',
			[__CLASS__, 'writePriceChangesToHLBlock']
		);
		$eventManager->addEventHandlerCompatible(
			'catalog',
			'OnBeforePriceDelete',
			[__CLASS__, 'preparePriceChangesToHLBlock']
		);
		$eventManager->addEventHandlerCompatible(
			'catalog',
			'OnPriceDelete',
			[__CLASS__, 'onPriceDelete']
		);
		$eventManager->addEventHandlerCompatible(
			'catalog',
			'OnProductPriceDelete',
			[__CLASS__, 'onProductPriceDelete']
		);
		//Обработка изменений цены по запросу
		$eventManager->addEventHandlerCompatible(
			'iblock',
			'OnBeforeIBlockElementUpdate',
			[__CLASS__, 'onBeforeIBlockElementUpdate']
		);
		$eventManager->addEventHandlerCompatible(
			'iblock',
			'OnAfterIBlockElementUpdate',
			[__CLASS__, 'onAfterIBlockElementUpdate']
		);
		//Обработчики SetPropertyValuesEx для импортов без обновления инфоблока
		$eventManager->addEventHandlerCompatible(
			"iblock",
			"OnIBlockElementSetPropertyValuesEx",
			[__CLASS__, 'onBeforeSetPropertyValuesEx']
		);
		$eventManager->addEventHandlerCompatible(
			"iblock",
			"OnAfterIBlockElementSetPropertyValuesEx",
			[__CLASS__, 'onAfterSetPropertyValuesEx']
		);
		// Если редактируем Цена по запросу в списке элементов
		$eventManager->addEventHandlerCompatible('main', 'OnEpilog', [__CLASS__, 'onEpilog']);
	}

	public static function setProcessingProductId(?int $processingProductId): void
	{
		static::$processingProductId = $processingProductId;
	}

	public static function onEpilog(): void
	{
		$scriptName = Application::getInstance()->getContext()->getRequest()->getScriptFile();
		if ($scriptName !== '/bitrix/admin/iblock_list_admin.php') {
			return;
		}

		if (!static::$priceOnRequest) {
			return;
		}

		$iterator = PriceTable::query()
			->addSelect('ID')
			->addSelect('PRICE')
			->addSelect('CURRENCY')
			->addSelect('CATALOG_GROUP_ID')
			->addSelect('ELEMENT.IBLOCK_ID', 'IBLOCK_ID')
			->addSelect('PRODUCT_ID')
			->whereIn('PRODUCT_ID', array_keys(static::$priceOnRequest))
			->exec();

		while ($price = $iterator->fetch()) {
			$fields = [
				'PRICE' => $price['PRICE'],
				'CURRENCY' => $price['CURRENCY' ],
				'CATALOG_GROUP_ID' => $price['CATALOG_GROUP_ID'],
				'PRODUCT_ID' => $price['PRODUCT_ID'],
				'IBLOCK_ID' => $price['IBLOCK_ID'],
			];
			static::$oldPrices[$price['PRODUCT_ID']][$price['CATALOG_GROUP_ID']] = $fields;

			static::writePriceChangesToHLBlock($price['ID'], $fields);
		}
	}

	public static function onBeforeSetPropertyValuesEx(
		int $elementId,
		int $iblockId,
		array $propertyValues,
		array $propertyList,
		array $currentValues
	): void {
		if (
			$iblockId !== CATALOG_IBLOCK_ID
			|| !array_key_exists(static::getPriceOnRequestPropertyId(), $propertyList)
		) {
			return;
		}

		if (!isset(static::$productIblockIds[$elementId])) {
			static::$productIblockIds[$elementId] = $iblockId;
		}

		static::$priceOnRequest[$elementId] = [
			'before' => (bool)static::getPropertyValue(
				static::getPriceOnRequestPropertyId(),
				'PRICE_ON_REQUEST',
				$currentValues
			),
			'after' => null,
		];
	}

	public static function onAfterSetPropertyValuesEx(
		int $elementId,
		int $iblockId,
		array $propertyValues,
		array $flags
	): void {
		if (
			$iblockId !== CATALOG_IBLOCK_ID
			|| !array_key_exists('PRICE_ON_REQUEST', $propertyValues)
		) {
			return;
		}

		static::$priceOnRequest[$elementId]['after'] = (bool)static::getPropertyValue(
			static::getPriceOnRequestPropertyId(),
			'PRICE_ON_REQUEST',
			$propertyValues
		);
	}

	private static function getPropertyValue(int $propertyId, string $propertyCode, array $properties): ?string
	{
		if (isset($properties[$propertyId])) {
			$value = $properties[$propertyId];
			if (is_array($value)) {
				$value = current($value)['VALUE'] ?? '';
			}

			return $value;
		}

		return $properties[$propertyCode] ?? null;
	}

	/**
	 * Записываем старую цену, пока её еще можно достать
	 *
	 * @param int   $id
	 * @param array $fields
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function preparePriceChangesToHLBlock(int $id, array $fields = []): void
	{
		if (isset(static::$priceIdToProduct[$id])) {
			return;
		}

		$iterator = PriceTable::query()
			->addSelect('ID')
			->addSelect('PRICE')
			->addSelect('CURRENCY')
			->addSelect('CATALOG_GROUP_ID')
			->addSelect('ELEMENT.IBLOCK_ID', 'IBLOCK_ID')
			->addSelect('PRODUCT_ID')
			->whereIn(
				'PRODUCT_ID',
				PriceTable::query()
					->addSelect('PRODUCT_ID')
					->where('ID', $id)
			)
			->exec();

		while ($row = $iterator->fetch()) {
			static::$oldPrices[$row['PRODUCT_ID']][$row['CATALOG_GROUP_ID']] = array_merge(
				$row, ['SOURCE' => 'PRICE']
			);
			static::$priceIdToProduct[(int)$row['ID']] = (int)$row['PRODUCT_ID'];
			if (!isset(static::$productIblockIds[(int)$row['PRODUCT_ID']])) {
				static::$productIblockIds[(int)$row['PRODUCT_ID']] = (int)$row['IBLOCK_ID'];
			}
		}
	}

	public static function onPriceDelete(int $id): void
	{
		if (!$productId = static::$priceIdToProduct[$id]) {
			return;
		}

		$oldProductPrices = static::$oldPrices[$productId] ?? [];
		$oldPrice = Arr::first($oldProductPrices, fn(array $priceData) => (int)$priceData['ID'] === $id);
		if ($oldPrice) {
			static::writePriceChangesToHLBlock(
				(int)$oldPrice['ID'],
				[
					'CATALOG_GROUP_ID' => $oldPrice['CATALOG_GROUP_ID'],
					'PRODUCT_ID' => $oldPrice['PRODUCT_ID'],
					'IBLOCK_ID' => $oldPrice['IBLOCK_ID'],
					'PRICE' => 0,
				]
			);
		}
	}

	public static function onProductPriceDelete(int $productId, array $excludedIds): void
	{
		$oldProductPrices = static::$oldPrices[$productId] ?? [];
		foreach ($oldProductPrices as $oldPrice) {
			if (in_array($oldPrice['ID'], $excludedIds)) {
				continue;
			}

			static::writePriceChangesToHLBlock(
				(int)$oldPrice['ID'],
				[
					'CATALOG_GROUP_ID' => $oldPrice['CATALOG_GROUP_ID'],
					'PRODUCT_ID' => $oldPrice['PRODUCT_ID'],
					'IBLOCK_ID' => $oldPrice['IBLOCK_ID'],
					'PRICE' => 0,
				]
			);
		}
	}

	public static function onBeforeIBlockElementUpdate(array $fields): void
	{
		$iblockId = (int)$fields['IBLOCK_ID'];
		if (!in_array($iblockId, [CATALOG_IBLOCK_ID, CATALOG_OFFERS_IBLOCK_ID])) {
			return;
		}

		if (!isset(static::$productIblockIds[(int)$fields['ID']])) {
			static::$productIblockIds[(int)$fields['ID']] = $iblockId;
		}

		$elementId = (int)$fields['ID'];

		static::$priceOnRequest[$elementId] = [
			'before' => static::getPriceOnRequestForElement($elementId, $iblockId),
			'after' => null,
		];

		if ($iblockId === CATALOG_OFFERS_IBLOCK_ID) {
			static::$priceOnRequest[static::getMainProductId($elementId)] = static::$priceOnRequest[$elementId];
		}
	}

	public static function onAfterIBlockElementUpdate(array $fields): void
	{
		$iblockId = (int)$fields['IBLOCK_ID'];
		if (!in_array($iblockId, [CATALOG_IBLOCK_ID, CATALOG_OFFERS_IBLOCK_ID])) {
			return;
		}

		$elementId = (int)$fields['ID'];

		if ($iblockId === CATALOG_OFFERS_IBLOCK_ID) {
			static::$priceOnRequest[$elementId]['after'] = static::$priceOnRequest[$elementId]['before'];
			static::$priceOnRequest[static::getMainProductId($elementId)]['after'] = static::$priceOnRequest[$elementId]['after'];

			return;
		}

		$priceOnRequestProperty = $fields['PROPERTY_VALUES'][static::getPriceOnRequestPropertyId()] ?: [];
		$baseValue = current($priceOnRequestProperty);
		if ($baseValue === "") {
			static::$priceOnRequest[$elementId]['after'] = count($priceOnRequestProperty) === 2;
		} else {
			static::$priceOnRequest[$elementId]['after'] = (bool)$baseValue['VALUE'];
		}
	}

	private static function getPriceOnRequestForElement(int $elementId, int $iblockId = 0): bool
	{
		$mainProduct = $iblockId === CATALOG_OFFERS_IBLOCK_ID ? static::getMainProductId($elementId) : 0;

		return (bool)IblockElementPropertyTable::query()
			->addSelect('VALUE')
			->where('IBLOCK_ELEMENT_ID', $mainProduct ?: $elementId)
			->where('IBLOCK_PROPERTY_ID', static::getPriceOnRequestPropertyId())
			->fetch()['VALUE'];
	}

	private static function getMainProductId(int $offerId): int
	{
		if (!isset(static::$productByOffer[$offerId])) {
			static::$productByOffer[$offerId] = (int)IblockElementPropertyTable::query()
				->addSelect('VALUE')
				->where('IBLOCK_ELEMENT_ID', $offerId)
				->where('IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_OFFERS_IBLOCK_ID)
				->where('IBLOCK_PROPERTY.CODE', 'CML2_LINK')
				->fetch()['VALUE'] ?? 0;
		}

		return static::$productByOffer[$offerId];
	}

	private static function getPriceOnRequestPropertyId(): int
	{
		if (!static::$priceOnRequestPropertyId) {
			static::$priceOnRequestPropertyId = PropertyTable::query()
				->addSelect('ID')
				->where('IBLOCK_ID', CATALOG_IBLOCK_ID)
				->where('CODE', 'PRICE_ON_REQUEST')
				->exec()
				->fetch()['ID'] ?? 0;
		}

		return static::$priceOnRequestPropertyId;
	}

	/**
	 * Записываем статистику изменения цены
	 *
	 * @param       $id
	 * @param array $fields
	 */
	public static function writePriceChangesToHLBlock(int $id, array $fields = []): void
	{
		$oldPrice = [];
		if (!isset($fields['CATALOG_GROUP_ID']) && $id) {
			$fields['CATALOG_GROUP_ID'] = self::getPriceType($id);
		}
		if (isset(static::$oldPrices[$fields['PRODUCT_ID']][$fields['CATALOG_GROUP_ID']])) {
			$oldPrice = static::$oldPrices[$fields['PRODUCT_ID']][$fields['CATALOG_GROUP_ID']];
			unset(static::$oldPrices[$fields['PRODUCT_ID']][$fields['CATALOG_GROUP_ID']]);
		}

		$productIblockId = static::getIblockIdForProduct((int)$fields['PRODUCT_ID']);
		$priceOnRequestChanges = static::getPriceOnRequestChanges(
			(int)$fields['PRODUCT_ID'],
			$productIblockId
		);

		if ($priceOnRequestChanges['before'] && isset($oldPrice['PRICE'])) {
			$oldPrice['PRICE'] = 0;
		}

		if ($priceOnRequestChanges['after'] && isset($fields['PRICE'])) {
			$fields['PRICE'] = 0;
		}

		if (!$oldPrice && !$fields['PRICE']) {
			return;
		}

		if (!$oldPrice) {
			static::writeToHLBlock((int)$fields['PRODUCT_ID'], $oldPrice, $fields);
		} elseif (
			$oldPrice['PRICE'] != $fields['PRICE']
			|| $oldPrice['CURRENCY'] != $fields['CURRENCY']
		) {
			static::writeToHLBlock((int)$fields['PRODUCT_ID'], $oldPrice, $fields);
		}

		$priceOnRequestChanged = $priceOnRequestChanges['before'] !== $priceOnRequestChanges['after'];
		//Изменения отображение цены основного товара по всей видимости затрагивает и торговые предложения
		if ($priceOnRequestChanged && $productIblockId === CATALOG_IBLOCK_ID) {
			$groupId = (int)$fields['CATALOG_GROUP_ID'];
			$offers = static::getOffersForProduct((int)$fields['PRODUCT_ID'], $groupId);
			foreach ($offers as $offer) {
				$currentPriceValue = [
					'PRICE' => $offer['CATALOG_PRICE_' . $groupId],
					'CURRENCY' => $offer['CATALOG_CURRENCY_' . $groupId],
					'CATALOG_GROUP_ID' => $groupId,
				];
				$emptyPrice = [
					'PRICE' => null,
					'CURRENCY' => $currentPriceValue['CURRENCY'],
					'CATALOG_GROUP_ID' => $groupId,
				];

				if (!$currentPriceValue['PRICE']) {
					continue;
				}

				static::writeToHLBlock(
					(int)$offer['ID'],
					$priceOnRequestChanges['after'] ? $currentPriceValue : $emptyPrice,
					$priceOnRequestChanges['after'] ? $emptyPrice : $currentPriceValue,
				);
			}
		}
	}

	private static function getPriceType(int $priceId): string
	{
		return (string)PriceTable::query()
			->addSelect('CATALOG_GROUP_ID')
			->where('ID', $priceId)
			->exec()
			->fetch()['CATALOG_GROUP_ID'] ?: '';
	}

	private static function getIblockIdForProduct(int $productId): int
	{
		if (!isset(static::$productIblockIds[$productId])) {
			static::$productIblockIds[$productId] = (int)ElementTable::query()
				->addSelect('IBLOCK_ID')
				->where('ID', $productId)
				->exec()
				->fetch()['IBLOCK_ID'];
		}

		return static::$productIblockIds[$productId];
	}

	private static function getOffersForProduct(int $productId, int $catalogGroupId): array
	{
		$select = [
			'ID',
			'CATALOG_PRICE_' . $catalogGroupId,
		];
		$filter = [
			'=PROPERTY_CML2_LINK' => $productId,
			'=ACTIVE' => 'Y',
		];
		$sort = ['SORT' => 'ASC'];
		return IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)
			->getElementsByFilter($filter, $select, 0, $sort);
	}

	private static function getPriceOnRequestChanges(int $elementId, int $iblockId): array
	{
		if (!isset(static::$priceOnRequest[$elementId])) {
			$priceOnRequest = static::getPriceOnRequestForElement($elementId, $iblockId);
			static::$priceOnRequest[$elementId] = [
				'before' => $priceOnRequest,
				'after' => $priceOnRequest,
			];
		}

		return static::$priceOnRequest[$elementId];
	}

	public static function writeToHLBlock(int $id, array $oldPrice, array $newPrice): void
	{
		$userId = User::getId();
		$userLogin = User::getLogin();

		$hlBlockClassName = HighloadblockHelper::forHighloadblock(PRICE_CHANGES_HLBLOCK_ID)->getEntityClass();
		$priceTypeId = (int)$newPrice['CATALOG_GROUP_ID'] ?? BASE_PRICE_ID;
		(new $hlBlockClassName)->createObject()
			->setUfItemId($id)
			->setUfCatalogGroupId($priceTypeId)
			->setUfOldPrice($oldPrice['PRICE'] ?? '')
			->setUfOldCurrency($oldPrice['CURRENCY'] ?? '')
			->setUfNewPrice($newPrice['PRICE'] ?: 'Цена удалена')
			->setUfNewCurrency($newPrice['CURRENCY'] ?? $oldPrice['CURRENCY'] ?? '')
			->setUfDateUpdate(new Datetime)
			->setUfUser("[$userId] $userLogin")
			->setUfFromExchange($id === static::$processingProductId)
			->save();
		unset(static::$oldPrices[$id][$priceTypeId]);
	}
}