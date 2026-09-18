<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Logema\Utils\DataAccess\HighloadblockHelper;
use Logema\Utils\DataAccess\IblockPropertyValueExtractor;
use Rusgeocom\Rusgeocom\Ui\Tools;
use Rusgeocom\Rusgeocom\Utils\Image;
use Rusgeocom\Rusgeocom\Utils\Url;

class SectionTemplateHelper
{
	protected $items = [];

	protected function __construct(array $items)
	{
		$this->items = $items;
		$itemIds = array_column($items, 'ID');

		// Товары в наличии
		$inStockProductIds = array_keys(Availability::getInStockProductIds($itemIds));

		// Рейтинг
		$rates = Tools::getRatesByIds($itemIds);

		// Картинки брендов
		static::fillEmptyDetailImages($items);

		foreach ($items as $kItem => $item){

			$props = IblockPropertyValueExtractor::forElement($item);

			// Главное торговое предложение
			$mainOffer = reset($item['OFFERS']);

			// Обрезаем имя для мобилки
			$item['NAME'] = htmlspecialchars_decode($item['NAME']);
			$item['NAME_SHORT'] = TruncateText($item['NAME'], 44);

			// Главная картинка
			$item['IMAGE'] = Image::resize($item['DETAIL_PICTURE'], Image::ITEMS_LIST_SIZE);

			// Картинки для мобилок
			$item['IMAGES'] = Tools::extractPictures($item);

			// Рейтинг
			$item['RATING'] = $rates[$item['ID']];

			// Поверка
			$item['HAS_VERIFICATION_CERTIFICATE'] = !!$props['HAS_VERIFICATION_CERTIFICATE'];

			// Ссылка на товар
			$item['URL'] = Url::formatSlash($props['ALIASE']);

			// ID для добавления в корзину и избранное
			$item['OFFER_ID'] = $mainOffer['ID'] ?: $item['ID'];

			// Цены
			Price::fillPriceForProduct($item, $mainOffer);

			// В наличии
			$item['IN_STOCK'] = in_array($item['ID'], $inStockProductIds);
			$item['SHOW_IN_STOCK'] = $item['IN_STOCK'] || $props['SHOW_OUT_OF_STOCK'] == 'Да';

			// Заголовок
			$item['H1'] = $props['H1'] ?: $item['NAME'];

			Tools::fillStocksForManagers($item);

			$this->items[$kItem] = $item;
		}
	}

	/**
	 * Заполнение пустых детальных картинок логотипами брендов
	 *
	 * @param array $products
	 */
	public static function fillEmptyDetailImages(array &$products): void
	{
		$brandCodes = [];
		foreach ($products as $product){
			if (!$product['DETAIL_PICTURE'] && $product['PROPERTIES']['BRAND_REF']) {
				$brandCodes[] = is_array($product['PROPERTIES']['BRAND_REF'])
					? $product['PROPERTIES']['BRAND_REF']['VALUE']
					: $product['PROPERTIES']['BRAND_REF'];
			}
		}
		if ($brandCodes) {
			$filter = ["UF_XML_ID" => $brandCodes];
			$select = ['UF_NO_FOTO', 'UF_XML_ID'];
			$brands = HighloadblockHelper::forHighloadblock(BRANDS_HLBLOCK_ID)->getElementsByFilter($filter, $select);
			$brandImageMap = [];
			foreach ($brands as $brand) {
				$brandImageMap[$brand['UF_XML_ID']] = $brand['UF_NO_FOTO'];
			}

			foreach ($products as $kProduct => $product){
				if (!$product['DETAIL_PICTURE']) {
					$productBrand = is_array($product['PROPERTIES']['BRAND_REF'])
						? $product['PROPERTIES']['BRAND_REF']['VALUE']
						: $product['PROPERTIES']['BRAND_REF'];
					$products[$kProduct]['DETAIL_PICTURE'] = $brandImageMap[$productBrand] ?? null;
				}
			}
		}
	}

	public static function create(array $items): SectionTemplateHelper
	{
		return new static($items);
	}

	public function getItems(): array
	{
		return $this->items;
	}

	public static function getTemplateSettings(int $templateId): array
	{
		$map = [
			14 => [
				'SHOW_BANNER' => true,
				'BANNER_SECTION_IDS' => [3850, 3849, 3848],
				'SHOW_SECTION_LIST' => true,
				'SHOW_MENU_LEFT_BOTTOM' => true,
				'SHOW_MENU_LEFT_TOP' => true,
				'SHOW_SORT' => true,
			],
			27 => [
				'SHOW_BANNER' => true,
				'SHOW_SECTION_LIST' => true,
				'SHOW_MENU_LEFT_BOTTOM' => true,
				'SHOW_MENU_LEFT_TOP' => true,
				'SHOW_SORT' => true,
			],
			7 => [
				'SHOW_BANNER' => true,
				'SHOW_SECTION_LIST' => true,
				'SHOW_MENU_LEFT_BOTTOM' => true,
				'SHOW_MENU_LEFT_TOP' => true,
				'SHOW_SORT' => true,
			],
			8 => [
				'SHOW_BANNER' => true,
				'SHOW_MENU_LEFT_BOTTOM' => true,
				'SHOW_MENU_LEFT_TOP' => true,
				'SHOW_SORT' => true,
			],
			28 => [
				'SHOW_MENU_LEFT_BOTTOM' => true,
				'SHOW_MENU_LEFT_TOP' => true,
				'SHOW_SORT' => true,
			],
			25 => [
				'SHOW_MENU_LEFT_BOTTOM' => true,
				'LEFT_BOTTOM_MENU_ROOT_ID' => VERIFICATION_SECTION_ID,
				'ORDER_ONLY' => true, // нельзя добавить в корзину, только заказать
			],
			102 => [
				'SHOW_BANNER' => true,
				'SHOW_SECTION_LIST' => true,
				'SHOW_MENU_LEFT_BOTTOM' => true,
				'HIDE_MENU_LEFT_TOP' => true,
				'SHOW_SORT' => true,
			],
			104 => [
				'SHOW_BANNER' => true,
				'SHOW_SECTION_LIST' => true,
				'SHOW_MENU_LEFT_BOTTOM' => true,
				'HIDE_MENU_LEFT_TOP' => true,
				'SHOW_SORT' => true,
			],
		];

		return $map[$templateId] ?: [];
	}
}