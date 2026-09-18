<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\Model\Price as BitrixPrice;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Application;
use Bitrix\Main\EventManager;
use CIBlockElement;
use Bitrix\Catalog\PriceTable;
use Illuminate\Support\Str;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Services\Complects;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;
use Rusgeocom\Rusgeocom\Orm\IblockElementPropertyTable;

class ComplectPriceCalculator
{
	private static array $productIdsByPriceId = [];
	private static array $productPrices = [];
	private static array $oldDeferredPriceUpdates = [];
	private static array $deferredPriceUpdates = [];
	private static array $recalculatedProductIds = [];
	private static array $productsWihUpdatedProperty = [];
	private static int $rrcForSyncPropertyId = 0;

	public static function bindEvents(): void
	{
		$eventManager = EventManager::getInstance();
		$eventManager->addEventHandlerCompatible('catalog', 'OnPriceAdd', [__CLASS__, 'onPriceChange']);
		$eventManager->addEventHandlerCompatible('catalog', 'OnBeforePriceUpdate', [__CLASS__, 'onBeforePriceUpdate']);
		$eventManager->addEventHandlerCompatible('catalog', 'OnPriceUpdate', [__CLASS__, 'onPriceChange']);
		$eventManager->addEventHandlerCompatible('catalog', 'OnBeforePriceDelete', [__CLASS__, 'onBeforePriceDelete']);
		$eventManager->addEventHandlerCompatible(
			'catalog',
			'OnProductPriceDelete',
			[__CLASS__, 'onProductPriceDelete']
		);
		$eventManager->addEventHandlerCompatible('catalog', 'OnPriceDelete', [__CLASS__, 'onPriceDelete']);
		$eventManager->addEventHandlerCompatible(
			'iblock',
			'OnAfterIBlockElementUpdate',
			[__CLASS__, 'onElementChange']
		);
		$eventManager->addEventHandlerCompatible('iblock', 'OnAfterIBlockElementAdd', [__CLASS__, 'onElementChange']);
		//Отложенное обновление РРЦ и Базовой цен
		$eventManager->addEventHandlerCompatible(
			'iblock',
			'OnBeforeIBlockElementUpdate',
			[__CLASS__, 'onBeforeIBlockElementUpdate']
		);
		$eventManager->addEventHandlerCompatible(
			'iblock',
			'OnIBlockElementSetPropertyValuesEx',
			[__CLASS__, 'onBeforeSetPropertyValuesEx']
		);
		$eventManager->addEventHandlerCompatible(
			'iblock',
			'OnAfterIBlockElementSetPropertyValuesEx',
			[__CLASS__, 'onAfterSetPropertyValuesEx']
		);
		$eventManager->addEventHandlerCompatible('main', 'OnEpilog', [__CLASS__, 'onEpilog']);
	}

	public static function onEpilog(): void
	{
		$scriptName = Application::getInstance()->getContext()->getRequest()->getScriptFile();
		if (!Str::startsWith($scriptName, '/bitrix/admin/')) {
			return;
		}

		$notProcessedDeferredUpdates = array_diff(
			array_keys(static::$deferredPriceUpdates),
			static::$recalculatedProductIds
		);
		if ($notProcessedDeferredUpdates) {
			static::recalculatePricesForProducts($notProcessedDeferredUpdates);
		}
	}

	public static function onBeforeSetPropertyValuesEx(
		int $elementId,
		int $iblockId,
		array $propertyValues,
		array $propertyList,
		array $currentValues
	): void {
		$propertyId = static::getRrcForSyncPropertyId();
		if (
			$iblockId !== CATALOG_IBLOCK_ID
			|| !array_key_exists($propertyId, $propertyList)
		) {
			return;
		}

		$propertyValue = $currentValues[$propertyId];
		if (is_array($propertyValue)) {
			$oldRrc = current($propertyValue)['VALUE'] ?? '';
		} else {
			$oldRrc = null;
		}

		static::$oldDeferredPriceUpdates[$elementId] = $oldRrc;
		static::fetchPricesForProduct($elementId);
	}

	public static function onAfterSetPropertyValuesEx(
		int $elementId,
		int $iblockId,
		array $propertyValues,
		array $flags
	): void {
		if (
			$iblockId !== CATALOG_IBLOCK_ID
			|| !array_key_exists('RRC_FOR_SYNC', $propertyValues)
		) {
			return;
		}

		$oldRrc = static::$oldDeferredPriceUpdates[$elementId] ?? '';
		$newRrc = $propertyValues['RRC_FOR_SYNC'] ?? '';
		static::checkRrcForSyncChanges($elementId, $oldRrc, $newRrc);
	}

	public static function onBeforeIBlockElementUpdate(array $fields): void
	{
		if ((int)$fields['IBLOCK_ID'] !== CATALOG_IBLOCK_ID) {
			return;
		}

		$productId = (int)$fields['ID'];
		static::$oldDeferredPriceUpdates[$productId] = trim(static::getRrcForSyncForElement($productId));
		static::fetchPricesForProduct($productId);
	}

	private static function checkRrcForSyncChanges(int $productId, string $old, string $new): void
	{
		if (Str::startsWith($old, '!') || Str::startsWith($new, '!')) {
			return;
		}

		if (preg_match('/^\d+$/', $new) && $old !== $new) {
			static::$deferredPriceUpdates[$productId] = (int)$new;
		}
	}

	private static function getRrcForSyncForElement(int $elementId): string
	{
		return IblockElementPropertyTable::query()
			->addSelect('VALUE')
			->where('IBLOCK_ELEMENT_ID', $elementId)
			->where('IBLOCK_PROPERTY_ID', static::getRrcForSyncPropertyId())
			->fetch()['VALUE'] ?: '';
	}

	private static function getRrcForSyncPropertyId(): int
	{
		if (!static::$rrcForSyncPropertyId) {
			static::$rrcForSyncPropertyId = PropertyTable::query()
				->addSelect('ID')
				->where('IBLOCK_ID', CATALOG_IBLOCK_ID)
				->where('CODE', 'RRC_FOR_SYNC')
				->exec()
				->fetch()['ID'] ?? 0;
		}

		return static::$rrcForSyncPropertyId;
	}

	public static function onProductPriceDelete(int $productId, array $excludedIds): void
	{
		$oldProductPrices = static::$productPrices[$productId] ?? [];
		foreach ($oldProductPrices as $oldPrice) {
			if (in_array($oldPrice, $excludedIds)) {
				continue;
			}

			self::recalculatePricesForProduct($productId);
			break;
		}
	}

	public static function onBeforePriceUpdate(int $id, array $fields): void
	{
		static::fetchPricesForProduct((int)$fields['PRODUCT_ID']);
	}

	private static function fetchPricesForProduct(int $productId): void
	{
		if (isset(static::$productPrices[$productId])) {
			return;
		}

		$iterator = PriceTable::query()
			->addSelect('ID')
			->addSelect('PRODUCT_ID')
			->whereIn('PRODUCT_ID', $productId)
			->exec();

		while ($row = $iterator->fetch()) {
			static::$productPrices[$productId][] = (int)$row['ID'];
			if (!isset(static::$productIdsByPriceId[(int)$row['ID']])) {
				static::$productIdsByPriceId[(int)$row['ID']] = $productId;
			}
		}
	}

	public static function onBeforePriceDelete(int $id): void
	{
		$price = PriceTable::getById($id)->fetch();
		if ($price['PRODUCT_ID']) {
			static::$productIdsByPriceId[$id] = (int)$price['PRODUCT_ID'];
		}
	}

	public static function onPriceDelete(int $id): void
	{
		if (static::$productIdsByPriceId[$id]) {
			self::recalculatePricesForProduct(static::$productIdsByPriceId[$id]);
		}
	}

	public static function onPriceChange($id, $fields): void
	{
		if ($fields['PRODUCT_ID']){
			static::recalculatePricesForProduct($fields['PRODUCT_ID']);
		}
	}

	public static function onElementChange(&$fields): void
	{
		if ($fields['IBLOCK_ID'] == CATALOG_IBLOCK_ID && $fields['ID']) {
			$productId = (int)$fields['ID'];

			$oldRrc = static::$oldDeferredPriceUpdates[$productId] ?? '';
			$rrcForUpdateProperty = $fields['PROPERTY_VALUES'][static::getRrcForSyncPropertyId()] ?: [];
			$newRrc = trim(current($rrcForUpdateProperty)['VALUE'] ?? '');

			static::checkRrcForSyncChanges($productId, $oldRrc, $newRrc);

			static::recalculatePricesForProduct($fields['ID']);
		}
	}

	public static function getProductIdByTradeOfferId($tradeOfferId): array
	{
		$productId = 0;

		$filter = [
			'IBLOCK_ID' => CATALOG_OFFERS_IBLOCK_ID,
			'ID' => $tradeOfferId,
		];
		$select = [
			'PROPERTY_CML2_LINK',
		];

	 	$tardeOffers = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)->getElementsByFilter($filter, $select, 0);

		foreach ($tardeOffers as $offer) {
			$productId = $offer['PROPERTY_CML2_LINK_VALUE'];
		}

		return [$productId];
	}


	/**
	 * Пересчитать цены комплектов, в которых есть товары с ценами в валютах
	 *
	 * @param string[] $currencies
	 * @return void
	 */
	public static function recalculatePricesForCurrencies(array $currencies): void
	{
		if (!$currencies){
			return;
		}

		$productIds = [];
		$iterator = PriceTable::query()
			->addSelect('PRODUCT_ID')
			->whereIn('CURRENCY', $currencies)
			->where('CATALOG_GROUP_ID', BASE_PRICE_ID)
			->exec();
		while ($row = $iterator->fetch()){
			$productIds[] = $row['PRODUCT_ID'];
		}

		static::recalculatePricesForProducts($productIds);
	}

	/**
	 * Проверить принадлежит ли товар комплекту и пересчитать цену комплекта
	 *
	 * @param int $productId
	 * @return void
	 */
	public static function recalculatePricesForProduct(int $productId): void
	{
		static::recalculatePricesForProducts([$productId]);
	}

	/**
	 * Пересчитать цены всех комплектов
	 *
	 * @return void
	 */
	public static function recalculateAll(): void
	{
		$complectIds = [];
		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addFilter('=IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addFilter('=IBLOCK_PROPERTY.CODE', 'COMPLECT_MAIN_PRODUCT')
			->addFilter('!=VALUE', false)
			->exec();
		while ($row = $iterator->fetch()){
			$complectIds[] = $row['IBLOCK_ELEMENT_ID'];
		}

		static::recalculatePricesForProducts(array_unique($complectIds));
	}


	public static function removeRepeatId($complectItemIds): array
	{
		$idsWithoutQuantity = [];

		foreach ($complectItemIds as $key=>$row) {
			[$id] = Complects::getIdAndQuantity($row);
			$idsWithoutQuantity[$id] = $id;

			if (!array_key_exists($id, $complectItemIds)
				||
				(array_key_exists($id, $complectItemIds) && $complectItemIds[$id] < $complectItemIds[$key])
			) {
				$complectItemIds[$id] = $complectItemIds[$key];
			}

			unset($complectItemIds[$key]);
		}

		foreach($idsWithoutQuantity as $key=>$row) {
			$idsWithoutQuantity[$key] = $complectItemIds[$key];
		}

		return $idsWithoutQuantity;
	}

	/**
	 * Проверить принадлежат ли товары комплектам и пересчитать цену комплектов
	 *
	 * @param int[] $productIds
	 * @return void
	 */
	public static function recalculatePricesForProducts(array $productIds): void
	{
		if (!$productIds) {
			return;
		}

		static::$recalculatedProductIds = array_merge(static::$recalculatedProductIds, $productIds);

		// Добавляем к списку комплекты и соседние товары в комплектах
		[$complects, $disabledCalcComplectsId] = static::getComplects($productIds);
		if (!$complects){
			[$complects, $disabledCalcComplectsId] = static::getComplects(static::getProductIdByTradeOfferId($productIds));

			if (!$complects) {
				return;
			}
		}

		$complectsItemsIds = [];
		foreach ($complects as $complectId => $itemIds){
			$complectsItemsIds[] = $complectId;
			foreach ($itemIds as $itemId){
				[$itemId] = Complects::getIdAndQuantity($itemId);

				$complectsItemsIds[] = $itemId;
			}
		}
		$productVariants = static::getProductVariants($complectsItemsIds);
		foreach ($productVariants as $variantIds){
			foreach ($variantIds as $variantId){
				$complectsItemsIds[] = $variantId;
			}
		}
		$complectsItemsIds = array_unique($complectsItemsIds);
		// Добавим еще и в рассчитанные остальные составные
		foreach ($complectsItemsIds as $complectsItemId) {
			if (!in_array($complectsItemId, static::$recalculatedProductIds)) {
				static::$recalculatedProductIds[] = $complectsItemId;
			}
		}

		$wholesalePrices = [
			PriceTypes::getWholesalePriceNameForLevel(1),
			PriceTypes::getWholesalePriceNameForLevel(2),
		];
		$priceTypes = array_merge(
			[BASE_PRICE_CODE],
			$wholesalePrices,
		);

		$prices = static::getCalculationPricesForProducts($complectsItemsIds, $priceTypes);
		$productDiscounts = static::getProductDiscounts($complects);
		$priceModifiers = static::getPriceModifiers(array_keys($complects));

		// Проверяем цены
		foreach ($complects as $complectId => $complectItemIds) {
			if (in_array($complectId, $disabledCalcComplectsId)) {
				continue;
			}

			$complectItemIds = static::removeRepeatId($complectItemIds);

			/** Исходная цена комплекта собирается из публичных цен его составляющих.
			 * Старая цена комплекта собирается из старых цен его составляющих,
			 * если старой цены нет, берем обычную цену
			 */
			$newComplectPrices = array_fill_keys($priceTypes, 0);
			$newDeferredComplectPrices = array_fill_keys($priceTypes, 0);
			$newOldComplectPrices = array_fill_keys($priceTypes, 0);
			$newPriceItemCount = array_fill_keys($priceTypes, 0);
			$newDeferredPriceItemCount = array_fill_keys($priceTypes, 0);
			$hasDeferredUpdate = false;
			foreach ($complectItemIds as $itemId) {
				[$itemId, $quantity] = Complects::getIdAndQuantity($itemId);

				if (!$quantity) {
					$quantity = Complects::DEFAULT_PRODUCT_QUANTITY;
				}

				$quantity = floor($quantity);

				foreach ($priceTypes as $priceType) {
					$currentComplectPrice = $prices[$complectId][$priceType]['PRICE'] ?? null;
					if ($currentComplectPrice === null && $newComplectPrices[$priceType] === null) {
						$newComplectPrices[$priceType] = null;
						continue;
					}

					$itemPrice = $prices[$itemId][$priceType]['PRICE'] ?? null;

					if (!$itemPrice) {
						$newComplectPrices[$priceType] = $currentComplectPrice !== null ? 0 : null;
						continue;
					}

					$itemCatalogGroup = $prices[$itemId][$priceType]['CATALOG_GROUP_ID'] ?? null;
					if (!isset($prices[$complectId][$priceType]['CATALOG_GROUP_ID']) && $itemCatalogGroup) {
						$prices[$complectId][$priceType]['CATALOG_GROUP_ID'] = $itemCatalogGroup;
					}

					$itemOldPrice = $prices[$itemId]['OLD_PRICE'] ?? $itemPrice;
					$itemCurrency = $prices[$itemId][$priceType]['CURRENCY'];
					if ($productVariants[$itemId]) {
						$itemVariantId = reset($productVariants[$itemId]);
						if (isset($prices[$itemVariantId][$priceType])) {
							$itemPrice = $prices[$itemVariantId][$priceType]['PRICE'];
							$itemOldPrice = $prices[$itemVariantId]['OLD_PRICE'] ?? $itemPrice;
							$itemCurrency = $prices[$itemVariantId][$priceType]['CURRENCY'];
						}
					}

					$itemPrice = Price::convertToBaseCurrency($itemPrice ?: 0, $itemCurrency ?: 'RUB');
					$itemOldPrice = Price::convertToBaseCurrency($itemOldPrice ?: 0, $itemCurrency ?: 'RUB');

					if (!in_array($priceType, $wholesalePrices)) {
						$itemPrice = $productDiscounts[$itemId]
							? $itemPrice / 100 * (100 - $productDiscounts[$itemId])
							: $itemPrice / 100 * (100 - $productDiscounts[$complectId]);
					}

					$newComplectPrices[$priceType] += $itemPrice * $quantity;
					$newOldComplectPrices[$priceType] += $itemOldPrice * $quantity;
					$newPriceItemCount[$priceType]++;
				}
			}

			foreach ($priceTypes as $priceType) {
				$newComplectPrice = $newComplectPrices[$priceType];
				if ($newComplectPrice === null) {
					continue;
				}

				if ($newPriceItemCount[$priceType] !== count($complectItemIds)) {
					$newComplectPrice = 0;
				}

				$currentComplectPrice = $prices[$complectId][$priceType]['PRICE'];
				$currentComplectCurrency = $prices[$complectId][$priceType]['CURRENCY'];
				if ($newComplectPrice) {
					$priceModifier = $priceModifiers[$complectId] ?: 0;
					$newComplectPrice += in_array($priceType, $wholesalePrices) ? 0 : $priceModifier;
					$newComplectPrice = round($newComplectPrice);
				}

				if ($currentComplectPrice !== $newComplectPrice) {
					$priceTypeId = $prices[$complectId][$priceType]['CATALOG_GROUP_ID'];
					$complectPriceId = (int)$prices[$complectId][$priceType]['PRICE_ID'];
					static::updatePrice($complectPriceId, $complectId, $newComplectPrice, $priceTypeId);
					static::saveHistory(
						$complectId,
						$currentComplectPrice ?? 0,
						$currentComplectCurrency ?? Price::CURRENCY_RUB,
						$newComplectPrice,
						$priceTypeId
					);

					foreach ($productVariants[$complectId] as $variantId) {
						$variantPriceId = (int)$prices[$variantId][$priceType]['PRICE_ID'];
						$oldVariantPrice = $prices[$variantId][$priceType]['PRICE'];
						$oldVariantCurrency = $prices[$variantId][$priceType]['PRICE'];
						static::updatePrice($variantPriceId, $variantId, $newComplectPrice, $priceTypeId);
						static::saveHistory(
							$variantId,
							$oldVariantPrice ?? 0,
							$oldVariantCurrency ?? Price::CURRENCY_RUB,
							$newComplectPrice,
							$priceTypeId
						);
					}
				}
			}
		}
	}

	private static function getProductDiscounts(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$complectItemIds = [];
		foreach ($productIds as $complectId=>$complectProductIds) {
			$complectItemIds[] = $complectId;
			foreach ($complectProductIds as $complectProductId) {
				$complectItemIds[] = $complectProductId;
			}
		}

		$discounts = [];
		$iterator = IblockElementPropertyTable::query()
			->addSelect('VALUE')
			->addSelect('IBLOCK_ELEMENT_ID')
			->addFilter('=IBLOCK_PROPERTY.CODE', 'COMPLECT_DISCOUNT_SIZE')
			->addFilter('=IBLOCK_ELEMENT_ID', $complectItemIds)
			->exec();
		while ($row = $iterator->fetch()){
			$discounts[$row['IBLOCK_ELEMENT_ID']] = abs((float)$row['VALUE']);
		}

		return $discounts;
	}

	private static function saveHistory(
		int $productId,
		int $oldPrice,
		string $oldCurrency,
		int $newPrice,
		int $priceTypeId
	): void {
		PriceHistory::writeToHLBlock(
			$productId,
			['PRICE' => (float)$oldPrice, 'CURRENCY' => $oldCurrency, 'CATALOG_GROUP_ID' => $priceTypeId],
			['PRICE' => (float)$newPrice, 'CURRENCY' => Price::CURRENCY_RUB, 'CATALOG_GROUP_ID' => $priceTypeId]
		);
	}

	private static function getDisabledCalcComplectsId(array $complects): array
	{
		if (!$complects){
			return [];
		}

		$complectIds = [];

		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addFilter('=IBLOCK_PROPERTY.CODE', 'DISABLE_CALC_PRICE')
			->addFilter('=IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addFilter('!=VALUE', false)
			->addFilter('=IBLOCK_ELEMENT_ID', array_keys($complects))
			->exec();
		while ($row = $iterator->fetch()){
			$complectIds[] = $row['IBLOCK_ELEMENT_ID'];
		}

		return $complectIds;
	}

	private static function getComplects(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$allItemIds = $productIds;

		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addFilter('=IBLOCK_PROPERTY.CODE', 'COMPLECT_MAIN_PRODUCT')
			->addFilter('=IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addFilter('=VALUE', $productIds)
			->exec();
		while ($row = $iterator->fetch()){
			$allItemIds[] = $row['IBLOCK_ELEMENT_ID'];
		}

		foreach ($productIds as $itemId) {
			$newProductIds[] = $itemId;
			$newProductIds[] = $itemId.'*%';
		}

		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addFilter('=IBLOCK_PROPERTY.CODE', 'COMPLECT_ITEMS')
			->addFilter('=IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addFilter('VALUE', $newProductIds)
			->exec();
		while ($row = $iterator->fetch()){
			$allItemIds[] = $row['IBLOCK_ELEMENT_ID'];
		}

		$complects = [];
		$iterator = IblockElementPropertyTable::query()
			->addSelect('IBLOCK_ELEMENT_ID')
			->addSelect('VALUE')
			->addSelect('IBLOCK_PROPERTY.CODE', 'PROPERTY_CODE')
			->addFilter('=IBLOCK_ELEMENT_ID', $allItemIds)
			->addFilter('=IBLOCK_PROPERTY.CODE', ['COMPLECT_MAIN_PRODUCT', 'COMPLECT_ITEMS'])
			->addFilter('=IBLOCK_ELEMENT.IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addFilter('!=VALUE', false)
			->exec();
		while ($row = $iterator->fetch()){
			$complectId = $row['IBLOCK_ELEMENT_ID'];
			$itemId = $row['VALUE'];
			if (!in_array($itemId, $complects[$complectId] ?? [])) {
				$complects[$complectId][] = $itemId;
			}
		}

		$disablesCalcComplects = static::getDisabledCalcComplectsId($complects);

		return [$complects, $disablesCalcComplects];
	}

	public static function getProductVariants(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$productVariants = [];

		$select = ['ID', 'PROPERTY_CML2_LINK'];
		$filter = [
			'ACTIVE' => 'Y',
			'PROPERTY_CML2_LINK' => $productIds,
			'PROPERTY_CHECK' => false,
		];
		$sort = ['SORT' => 'ASC'];
		$offers = IblockHelper::forIblock(CATALOG_OFFERS_IBLOCK_ID)->getElementsByFilter($filter, $select, 0, $sort);
		foreach ($offers as $offer){
			$productVariants[$offer['PROPERTIES']['CML2_LINK']['VALUE']][] = $offer['ID'];
		}

		return $productVariants;
	}

	private static function getPriceModifiers(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$modifiers = [];
		$iterator = IblockElementPropertyTable::query()
			->addSelect('VALUE')
			->addSelect('IBLOCK_ELEMENT_ID')
			->where('IBLOCK_PROPERTY.CODE', Price::PRICE_MODIFIER_CODE)
			->whereIn('IBLOCK_ELEMENT_ID', $productIds)
			->exec();
		while ($row = $iterator->fetch()){
			$modifiers[$row['IBLOCK_ELEMENT_ID']] = (int)$row['VALUE'];
		}

		return $modifiers;
	}

	public static function getPricesForProducts(array $productIds): array
	{
		if (!$productIds){
			return [];
		}

		$prices = [];
		$iterator = PriceTable::query()
			->setSelect([
				'ID',
				'PRODUCT_ID',
				'CURRENCY',
				'PRICE',
				'CATALOG_GROUP_ID',
			])
			->addFilter('=PRODUCT_ID', $productIds)
			->addFilter('=CATALOG_GROUP_ID', BASE_PRICE_ID)
			->exec();

		while ($row = $iterator->fetch()){
			$prices[$row['PRODUCT_ID']]['PRICE'] = (float)$row['PRICE'];
			$prices[$row['PRODUCT_ID']]['CURRENCY'] = $row['CURRENCY'];
			$prices[$row['PRODUCT_ID']]['PRICE_ID'] = (int)$row['ID'];
			$prices[$row['PRODUCT_ID']]['OLD_PRICE'] = CIBlockElement::GetProperty(
				CATALOG_IBLOCK_ID,
				$row['PRODUCT_ID'],
				['sort' => 'asc'],
				['CODE'=>'PRICE_OLD']
			)->Fetch()['VALUE'];
		}

		return $prices;
	}

	private static function getCalculationPricesForProducts(array $productIds, array $priceTypesCodes): array
	{
		if (!$productIds){
			return [];
		}

		$prices = [];
		$iterator = PriceTable::query()
			->addSelect('ID')
			->addSelect('PRODUCT_ID')
			->addSelect('CURRENCY')
			->addSelect('PRICE')
			->addSelect('CATALOG_GROUP_ID')
			->addSelect('CATALOG_GROUP.NAME', 'CATALOG_GROUP_CODE')
			->whereIn('PRODUCT_ID', $productIds)
			->whereIn('CATALOG_GROUP.NAME', $priceTypesCodes)
			->exec();

		while ($row = $iterator->fetch()){
			$prices[$row['PRODUCT_ID']][$row['CATALOG_GROUP_CODE']]['PRICE'] = (float)$row['PRICE'];
			$prices[$row['PRODUCT_ID']][$row['CATALOG_GROUP_CODE']]['CURRENCY'] = $row['CURRENCY'];
			$prices[$row['PRODUCT_ID']][$row['CATALOG_GROUP_CODE']]['PRICE_ID'] = (int)$row['ID'];
			$prices[$row['PRODUCT_ID']][$row['CATALOG_GROUP_CODE']]['CATALOG_GROUP_ID'] = (int)$row['CATALOG_GROUP_ID'];
		}

		$oldPriceIterator = CIBlockElement::GetPropertyValues(
			CATALOG_IBLOCK_ID,
			['ID' => array_keys($prices)],
			false,
			['CODE' => 'PRICE_OLD']
		);
		while ($oldPrice = $oldPriceIterator->Fetch()) {
			$prices[$oldPrice['IBLOCK_ELEMENT_ID']]['OLD_PRICE'] = $oldPrice['VALUE'];
		}

		return $prices;
	}

	private static function updatePrice(int $id, int $productId, int $price, int $priceTypeId): void
	{
		if ($id && !$price) {
			BitrixPrice::delete($id);
			return;
		}

		$fields = [
			'PRICE' => (float)$price,
			'CURRENCY' => Price::CURRENCY_RUB,
			'PRODUCT_ID' => $productId,
			'CATALOG_GROUP_ID' => $priceTypeId,
		];

		if ($priceTypeId !== BASE_PRICE_ID && !$id) {
			BitrixPrice::Add($fields);
		} else {
			BitrixPrice::update($id, $fields);
		}
	}

	private static function updateOldPrice(int $productId, int $oldPrice): void
	{
		$fields = [
			'PRICE_OLD' => $oldPrice,
		];
		CIBlockElement::SetPropertyValuesEx(
			$productId,
			CATALOG_IBLOCK_ID,
			$fields
		);
	}
}