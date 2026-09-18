<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\Model\Price;
use Bitrix\Catalog\Model\Product as BitrixProduct;
use Bitrix\Catalog\ProductTable;
use Bitrix\Currency\CurrencyManager;
use CIBlockElement;
use CUtil;
use Logema\Utils\DataAccess\IblockHelper;

class ProductCreator
{
	private const string ARCHIVE_SECTION_CODE = 'archive';
	private const string PROPERTY_MANAGERS_ONLY_CODE = 'SHOW_FOR_MANAGERS_ONLY';

	private static ?int $archiveSectionId = null;
	private static ?int $forManagersOnlyEnumId = null;

	public static function createSimpleProduct(string $name, float $price, string $utCode = ''): int
	{
		$element = new CIBlockElement;
		$productId = $element->Add(
			[
				'IBLOCK_ID' => CATALOG_IBLOCK_ID,
				'IBLOCK_SECTION_ID' => static::getArchiveSectionId(),
				'NAME' => $name,
				'CODE' => CUtil::translit($name, "ru", ['replace_space'=>'-','replace_other'=>'-']),
				'PREVIEW_TEXT' => $name,
				'DETAIL_TEXT' => $name,
				'PROPERTY_VALUES' => [
					'H1' => $name,
					'H1_FOR_MARKET_FEED' => $name,
					'UT_GUID_NEW' => $utCode,
					'SHOW_FOR_MANAGERS_ONLY' => static::getForManagersOnlyEnumId(),
				],
			]
		);

		Price::add([
			'CATALOG_GROUP_ID' => BASE_PRICE_ID,
			'PRODUCT_ID' => $productId,
			'PRICE' => $price,
			'CURRENCY' => CurrencyManager::getBaseCurrency(),
		]);

		BitrixProduct::add([
			'ID' => $productId,
			'TYPE' => ProductTable::TYPE_PRODUCT,
			//'AVAILABLE' => 'N',
			//VAT_ID,
			//VAT_INCLUDED,
		]);

		return $productId;
	}

	public static function getArchiveSectionId(): int
	{
		if (!static::$archiveSectionId) {
			static::$archiveSectionId = (int)current(
				static::getHelper()->getSectionByCode(static::ARCHIVE_SECTION_CODE, ['ID'])
			)['ID'] ?? 0;
		}

		return static::$archiveSectionId;
	}

	public static function getForManagersOnlyEnumId(): int
	{
		if (!static::$forManagersOnlyEnumId) {
			$propertyHelper = static::getHelper()->getIblockPropertyHelper();
			static::$forManagersOnlyEnumId = (int)$propertyHelper->getPropertyEnumByXmlId(
				$propertyHelper->getPropertyByCode(static::PROPERTY_MANAGERS_ONLY_CODE),
				'Y'
			)['ID'];
		}

		return static::$forManagersOnlyEnumId;
	}

	private static function getHelper(): IblockHelper
	{
		return IblockHelper::forIblock(CATALOG_IBLOCK_ID);
	}
}