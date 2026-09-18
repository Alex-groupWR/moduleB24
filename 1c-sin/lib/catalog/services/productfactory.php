<?php
namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\FileTable;
use Logema\Utils\DataAccess\IblockPropertyHelper;
use Rusgeocom\Rusgeocom\Catalog\Availability;
use Rusgeocom\Rusgeocom\Catalog\Entities\Badge;
use Rusgeocom\Rusgeocom\Catalog\Entities\Product;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductCollection;
use Rusgeocom\Rusgeocom\Catalog\SectionTemplateHelper;
use Rusgeocom\Rusgeocom\Favorites;
use Rusgeocom\Rusgeocom\Geoip\BranchCityService;
use Rusgeocom\Rusgeocom\Orm\UserFieldEnumTable;
use Rusgeocom\Rusgeocom\Ui\Tools;
use Rusgeocom\Rusgeocom\Utils\HlBlockHelperRegistry;

class ProductFactory
{
	private static $instance;

	public static function getInstance(): ProductFactory
	{
		if (!static::$instance){
			static::$instance = new ProductFactory;
		}

		return static::$instance;
	}

	public function makeProducts(array $elements): ProductCollection
	{
		if (!$elements){
			return new ProductCollection();
		}

		$itemIds = array_column($elements, 'ID');

		$currentBranch = BranchCityService::getInstance()->getCurrentCity();

		// Товары в наличии
		$productIdsWithInStockFlag = Availability::getInStockProductIds($itemIds, branch: $currentBranch);

		$this->fillImagesDescription($elements);
		// Рейтинг
		$rates = Tools::getRatesByIds($itemIds);

		// Картинки брендов
		SectionTemplateHelper::fillEmptyDetailImages($elements);

		$badges = $this->getBadgesByProducts($elements);

		$this->fillUniqueOffers($elements);
		$priceBags = $this->makePriceBags($elements);

		$products = new ProductCollection();
		foreach ($elements as $item){
			$itemId = $item['ID'];
			$productBadges = $badges[$itemId] ?: [];
			$rating = $rates[$itemId] ?: [];
			$product = new Product(
				$item,
				$rating,
				$productIdsWithInStockFlag[$item['ID']] ?? 0,
				$productBadges,
				$priceBags[$item['ID']],
			);
			$products->add($product);
		}

		return $products;
	}

	private function makePriceBags(array $products): array
	{
		$bags = [];

		$factory = PriceBagFactory::getInstance();
		foreach ($products as $product) {
			$bags[$product['ID']] = $factory->makeFromProductProperties($product);
		}

		return $bags;
	}

	public static function getOfferSelect(): array
	{
		return [
			'NAME',
			'PREVIEW_TEXT',
			'PROPERTY_CML2_LINK',
			'PROPERTY_CHECK',
			'PROPERTY_ARTIKUL',
			'PROPERTY_H1',
			'PROPERTY_ID_NOACTIV',
			'PROPERTY_POVERKA',
		];
	}

	/**
	 * Коды свойств с одиночными значениями
	 *
	 * @return string[]
	 */
	public static function getSelectSinglePropCodes(): array
	{
		return [
			'ARTICUL',
			'NAME_EN',
			'ALIASE',
			'HAS_VERIFICATION_CERTIFICATE',
			'ON_REQUEST',
			'PRICE_ON_REQUEST',
			'SHOW_OUT_OF_STOCK',
			'DISALLOW_BUY_OUT_OF_STOCK',
			'BTN_REQUEST_PRICE',
			'H1',
			'COMPARE_GROUP',
			'BRAND_REF',
			'BTN_VIEW',
			'PRICE_OLD',
			'CODE_GOSREESTR',
			'HIDE_UNDER_ORDER',
			'COMPLECT_MAIN_PRODUCT',
			'TRANSIT_POSTAV',
			'PRICE_MODIFIER',
			'COMPLECT_NAME',
			'UUID_1S_MULTI',
			'IMAGES_3D_FOLDER',
			'VENDOR_RESET_TIME',
			'ARCHIVE',
			'ACCESSOR_SORT',
			'HIDE_VAT_TEXT',
			'WORK_DAYS_ADD',
			'INDIVIDUAL_DISCOUNT',
			'RESTRICT_PICKUP',
			'DONT_SHOW_TO_WHOLESALERS',
			'UNIQUE_TRADE_OFFER',
			'IS_CORRECT_COMPLECT',
			'PRICE_WHOLESALE_1',
			'PRICE_WHOLESALE_2',
			'COUNT_FOR_PRICE_WHOLESALE_2',
			'COMPANY_DISCOUNT',
			'RRC',
			'RECEIPT_WITHOUT_VAT',
			'AUTH_DISCOUNT_HINT',
			'ADD_ROBOTS_NOINDEX',
			'APPLY_DISCOUNTS_WITHOUT_STOCK',
		];
	}

	/**
	 * Коды множественных свойств
	 *
	 * @return string[]
	 */
	public static function getSelectMultiPropCodes(): array
	{
		return [
			'PHOTO_OTHER',
			'BADGES',
			'COMPLECT_ITEMS',
			'RECOMM_TYPE',
			'BADGES',
		];
	}

	/**
	 * Поля
	 *
	 * @return string[]
	 */
	public static function getSelectFields(): array
	{
		return [
			'ID',
			'NAME',
			'ACTIVE',
			'IBLOCK_SECTION_ID',
			'PREVIEW_TEXT',
			'DETAIL_PICTURE',
		];
	}

	private function fillUniqueOffers(array &$products): void
	{
		$uniqueTradeOfferProperty = IblockPropertyHelper::forIblock(CATALOG_IBLOCK_ID)
			->getPropertyByCode('UNIQUE_TRADE_OFFER');

		$uniqueTradeOffers = IblockPropertyHelper::forIblock(CATALOG_IBLOCK_ID)
			->getPropertyEnum($uniqueTradeOfferProperty);

		foreach ($products as &$product) {
			if ($uniqueTradeOffer = $product['PROPERTIES']['UNIQUE_TRADE_OFFER']) {
				$product['PROPERTIES']['UNIQUE_TRADE_OFFER'] = $uniqueTradeOffers[$uniqueTradeOffer]['VALUE'] ?? '';
			}
		}
		unset($product);
	}

	private function getBadgesByProducts(array $products): array
	{
		$iterator = HlBlockHelperRegistry::getInstance()
			->getByCode('Badges')
			->getQuery()
			->setCacheTtl(3600)
			->cacheJoins(true)
			->addSelect('*')
			->addSelect('SHOW_PLACE.XML_ID', 'SHOW_PLACE_XML_ID')
			->addSelect('DISPLAY_LOCATION.XML_ID', 'DISPLAY_LOCATION_XML_ID')
			->registerRuntimeField('SHOW_PLACE', new ReferenceField(
				'SHOW_PLACE',
				UserFieldEnumTable::class,
				["=this.UF_SHOW_PLACE" => "ref.ID"],
				[]
			))
			->registerRuntimeField('DISPLAY_LOCATION', new ReferenceField(
				'DISPLAY_LOCATION',
				UserFieldEnumTable::class,
				["=this.UF_DISPLAY_LOCATION" => "ref.ID"],
				[]
			))
			->exec();

		while ($row = $iterator->fetch()) {
			$badgeData = [
				'UF_CODE' => $row['UF_XML_ID'],
				'UF_NAME' => $row['UF_NAME'],
				'UF_BACKGROUND_COLOR' => $row['UF_BACKGROUND_DETAIL_COLOR'],
				'UF_TEXT_COLOR' => $row['UF_DETAIL_TEXT_COLOR'],
				'UF_BORDER_COLOR' => '',
				'UF_SHOW_PLACE' => $row['SHOW_PLACE_XML_ID'],
				'UF_DISPLAY_LOCATION' => $row['DISPLAY_LOCATION_XML_ID'],
				'UF_PICTURE' => $row['UF_PICTURE'],
			];

			$badge = new Badge($badgeData);
			$badges[$badge->getCode()] = $badge;
		}
		$result = [];
		foreach ($products as $product) {
			$productBadges = $product['PROPERTIES']['BADGES'];

			foreach ($productBadges as $productBadge) {
				$result[$product['ID']][$productBadge] = $badges[$productBadge];
			}
			$result[$product['ID']][Badge::BADGE_GOSREESTR_CODE] = $this->getGosreestrBadge();
		}

		return $result;
	}

	/**
	 * Бейдж госреестра особенный и он не тянется из БД. Он добавляется ко всем товарам по умолчанию, и если у товара
	 * не заполнен номер госреестра, бейдж удаляется.
	 * Сделано так по просьбе Владимира Борисевича, т.к. не хотели чтобы эту плашку можно было выбрать у товара в
	 * админке
	 * @return Badge
	 */
	private function getGosreestrBadge(): Badge
	{
		$gostBadge["UF_CODE"] = Badge::BADGE_GOSREESTR_CODE;
		$gostBadge["UF_NAME"] = Badge::BADGE_GOSREESTR_TEXT;
		$gostBadge["UF_BACKGROUND_COLOR"] = Badge::BADGE_GOSREESTR_COLOR;
		$gostBadge["UF_TEXT_COLOR"] = Badge::BADGE_GOSREESTR_TEXT_COLOR;
		$gostBadge["UF_BORDER_COLOR"] = Badge::BADGE_GOSREESTR_BORDER_COLOR;
		$gostBadge["UF_SHOW_PLACE"] = Badge::BADGES_ALL_PLACE_CODE;
		$gostBadge["UF_DISPLAY_LOCATION"] = Badge::BADGES_ABOVE_TITLE_CODE;

		return new Badge($gostBadge);
	}

	private function fillImagesDescription(array &$products): void
	{
		$photoIds = [];
		foreach ($products as $item) {
			$photos = [
				$item['DETAIL_PICTURE'],
				...$item['PROPERTIES']['PHOTO_OTHER'] ?? [],
			];
			$photoIds[$item['ID']] = $photos;
		}

		if (!$photoIds) {
			return;
		}

		$result = FileTable::query()
			->setSelect(['ID', 'DESCRIPTION'])
			->whereIn('ID', array_merge(...$photoIds))
			->setCacheTtl(3600)
			->exec()
			->fetchAll();

		$files = [];
		foreach ($result as $file) {
			$files[$file['ID']] = $file['DESCRIPTION'];
		}


		foreach ($products as &$product){
			foreach ($photoIds[$product['ID']] as $photoId) {
				$product['PHOTO_DESCRIPTIONS'][$photoId] = trim(str_replace(['&', '<', '>', '\'', '"'], ' ', $files[$photoId]));
			}
		}
		unset($product);
	}
}