<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Iblock\SectionTable;
use Rusgeocom\Rusgeocom\Cache\FrontCacheInvalidator;

class ProductSectionActivator
{
	public static function bindEvents(): void
	{
		$eventManager = \Bitrix\Main\EventManager::getInstance();
		$eventManager->addEventHandlerCompatible('iblock', 'OnAfterIBlockElementAdd', [__CLASS__, 'onAfterElementAdd']);
		$eventManager->addEventHandlerCompatible('iblock', 'OnAfterIBlockElementUpdate', [__CLASS__, 'onAfterElementUpdate']);
		$eventManager->addEventHandlerCompatible('iblock', 'OnAfterIBlockSectionUpdate', [__CLASS__, 'onAfterSectionUpdate']);
	}

	public static function checkAndUpdateAllProducts(): void
	{
		$sections = [];
		$iterator = SectionTable::query()
			->addFilter('IBLOCK_ID', CATALOG_IBLOCK_ID)
			->addSelect('ID')
			->addSelect('IBLOCK_SECTION_ID')
			->addSelect('ACTIVE')
			->exec();
		while ($section = $iterator->fetch()){
			$sections[$section['ID']] = [
				'ID' => (int)$section['ID'],
				'ACTIVE' => $section['ACTIVE'] == 'Y',
				'PARENT_ID' => (int)$section['IBLOCK_SECTION_ID'],
			];
		}

		$select = [
			'ID',
			'IBLOCK_ID',
			'IBLOCK_SECTION_ID',
			'PROPERTY_NO_ACTIVE_BY_SECTIONS'
		];
		$filter = [
			'IBLOCK_ID' => CATALOG_IBLOCK_ID
		];
		$iterator = \CIBlockElement::GetList([], $filter, false, false, $select);
		while ($product = $iterator->Fetch()){
			$sectionId = (int)$product['IBLOCK_SECTION_ID'];
			$oldActive = !$product['PROPERTY_NO_ACTIVE_BY_SECTIONS_VALUE'];
			$newActive = true;
			$section = $sections[$sectionId];
			while ($section){
				if (!$section['ACTIVE']){
					$newActive = false;
					break;
				}
				$section = $sections[$section['PARENT_ID']];
			}

			if ($newActive != $oldActive){
				static::setProductActive($product['ID'], $newActive);
			}
		}
	}

	public static function checkAndUpdateProduct(int $productId): void
	{
		$select = [
			'IBLOCK_SECTION_ID',
			'PROPERTY_NO_ACTIVE_BY_SECTIONS'
		];
		$product = Catalog::getIblockHelper()->getElementById($productId, $select);

		if (!$product['IBLOCK_SECTION_ID']){
			return;
		}

		$newActive = static::calculateSectionActive($product['IBLOCK_SECTION_ID']);
		$oldActive = !$product['PROPERTY_NO_ACTIVE_BY_SECTIONS_VALUE'];

		if ($newActive != $oldActive){
			static::setProductActive($product['ID'], $newActive);
			FrontCacheInvalidator::invalidateProductsCache([$productId]);
		}
	}

	private static function calculateSectionActive(int $sectionId): bool
	{
		$navChain = \CIBlockSection::GetNavChain(CATALOG_IBLOCK_ID, $sectionId, ['ID', 'ACTIVE'], true);
		foreach ($navChain as $section){
			if ($section['ACTIVE'] != 'Y'){
				return false;
			}
		}

		return true;
	}

	public static function checkAndUpdateSectionProducts(int $sectionId): void
	{
		$newActive = static::calculateSectionActive($sectionId);

		$select = [
			'ID',
			'IBLOCK_ID',
			'PROPERTY_NO_ACTIVE_BY_SECTIONS'
		];
		$filter = [
			'IBLOCK_ID' => CATALOG_IBLOCK_ID,
			'IBLOCK_SECTION_ID' => $sectionId,
			'INCLUDE_SUBSECTIONS' => 'Y',
		];
		$iterator = \CIBlockElement::GetList([], $filter, false, false, $select);
		$productIds = [];
		while ($product = $iterator->Fetch()){
			$oldActive = !$product['PROPERTY_NO_ACTIVE_BY_SECTIONS_VALUE'];
			if ($newActive != $oldActive){
				static::setProductActive($product['ID'], $newActive);
				$productIds[] = $product['ID'];
			}
		}

		FrontCacheInvalidator::invalidateProductsCache($productIds);
	}

	private static function setProductActive(int $productId, bool $active): void
	{
		\CIBlockElement::SetPropertyValuesEx($productId, CATALOG_IBLOCK_ID, ['NO_ACTIVE_BY_SECTIONS' => !$active]);
	}

	public static function onAfterSectionUpdate(&$fields): void
	{
		if ($fields['IBLOCK_ID'] == CATALOG_IBLOCK_ID && $fields['ID']){
			static::checkAndUpdateSectionProducts($fields['ID']);
		}
	}

	public static function onAfterElementAdd(&$fields): void
	{
		if ($fields['IBLOCK_ID'] == CATALOG_IBLOCK_ID && $fields['ID']){
			static::checkAndUpdateProduct($fields['ID']);
		}
	}

	public static function onAfterElementUpdate(&$fields): void
	{
		if ($fields['IBLOCK_ID'] == CATALOG_IBLOCK_ID && $fields['ID']){
			static::checkAndUpdateProduct($fields['ID']);
		}
	}
}