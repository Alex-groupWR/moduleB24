<?php


namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Iblock\PropertyTable;
use CFile;
use CIBlockElement;
use CIBlockPropertyEnum;
use CUtil;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Api\Helpers\CatalogSectionPageHelper;
use Rusgeocom\Rusgeocom\Catalog\Entities\FilterPropHint;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;
use Rusgeocom\Rusgeocom\Seo\Tables\SeoRelinksProductsTable;
use Rusgeocom\Rusgeocom\Seo\Utils;
use Rusgeocom\Rusgeocom\Stores\StoreService;
use Rusgeocom\Rusgeocom\Types\Uri;
use Rusgeocom\Rusgeocom\Utils\ComponentExecutor;
use Rusgeocom\Rusgeocom\Utils\HlBlockHelperRegistry;
use Rusgeocom\Rusgeocom\Utils\Url;
use Rusgeocom\Rusgeocom\Utils\User;

class Filter {

	public static function onProlog()
	{
		static::checkRedirect($_SERVER['REQUEST_URI'] ?: '');
		static::initGlobalFilter();
	}

	private static function checkRedirect(string $url): void
	{
		if (strpos($url, '/f?') !== false){
			Url::redirect301(str_replace('/f?', '?', $url));
		}
	}

	protected static function initGlobalFilter()
	{
		global $arrFilterSale;
		$arrFilterSale = array(
			'IBLOCK_ID' => CATALOG_IBLOCK_ID,
			'ACTIVE'   	=> 'Y',
			'!PROPERTY_SPECIALOFFER' => false,
		);
	}

	/**
	 * Разрешает кеширование фильтра, если в нём только разрешённые ключи
	 * Кеширование фильтра отключено, но по всему каталогу есть разделы, использующие глобальный фильтр.
	 *
	 * @param array $filter
	 * @return bool
	 */
	public static function isFilterCacheAllowed($filter)
	{
		$allowedKeys = [
			'SECTION_ID',
			'INCLUDE_SUBSECTIONS',
			'PROPERTY_SALELEADER_VALUE',
			'PROPERTY_NEWPRODUCT_VALUE'
		];

		return count(array_intersect(array_keys($filter), $allowedKeys)) == count($filter);
	}

	/**
	 * Для менеджеров есть дополнительный фильтр.
	 * Этот метод достаёт его параметры из запроса и добавляет в глобальный фильтр
	 */
	public static function initManagerFilter(array $params): array
	{
		$stores = StoreService::getInstance()->getAll();

		$arFilter = [];
		$arFilter[0]['LOGIC'] = "OR";
		$mas = 'no';
		$mas1 = 'no';
		if (!empty($params['stores'])) {

			if (!empty($params['REZERV_MIN'])) {

				if (!empty($params['stores'])) {
					foreach ($params['stores'] as $storeId) {
						$arFilter[">=PROPERTY_" . $stores[$storeId]->getReservePropCode() . "_VALUE"] = $params['REZERV_MIN'];
					}
				}
			} else {
				if (!empty($params['stores'])) {
					foreach ($params['stores'] as $storeId) {
						$arFilter[">=PROPERTY_" . $stores[$storeId]->getReservePropCode() . "_VALUE"] = 0;
					}
				}
			}
			if (!empty($params['REZERV_MAX'])) {

				if (!empty($params['stores'])) {
					foreach ($params['stores'] as $storeId) {
						$arFilter["<=PROPERTY_" . $stores[$storeId]->getReservePropCode() . "_VALUE"] = $params['REZERV_MAX'];
					}
				}
			}

			if (!empty($params['quantity_from'])) {
				if (!empty($params['stores'])) {
					foreach ($params['stores'] as $storeId) {
						$arFilter[">=CATALOG_STORE_AMOUNT_" . $storeId] = $params['quantity_from'];
					}
				}
			} else {
				if (!empty($params['stores'])) {
					foreach ($params['stores'] as $storeId) {
						$arFilter[">CATALOG_STORE_AMOUNT_" . $storeId] = 0;
					}
				}
			}
			if (!empty($params['quantity_to']) && $params['quantity_to'] > 0) {
				foreach ($params['stores'] as $storeId) {
					$arFilter["<=CATALOG_STORE_AMOUNT_" . $storeId] = $params['quantity_to'];
				}
			}
		} else {
			if (!empty($params['REZERV_MAX']) && !empty($params['REZERV_MIN'])) {

				foreach ($stores as $store) {
					$arFilter[0][] = [
						">=PROPERTY_" . $store->getReservePropCode() . "_VALUE" => $params['REZERV_MIN'],
						"<=PROPERTY_" . $store->getReservePropCode() . "_VALUE" => $params['REZERV_MAX'],
					];
				}
				$mas = 'Y';
			} else {
				if (empty($params['REZERV_MAX']) && !empty($params['REZERV_MIN'])) {
					foreach ($stores as $store) {
						$arFilter[0][] = [
							">=PROPERTY_" . $store->getReservePropCode() . "_VALUE" => $params['REZERV_MIN']
						];
					}
					$mas = 'Y';
				} else {
					if (empty($params['REZERV_MIN']) && !empty($params['REZERV_MAX'])) {
						foreach ($stores as $store) {
							$arFilter[0][] = [
								">=PROPERTY_" . $store->getReservePropCode() . "_VALUE" => 0,
								"<=PROPERTY_" . $store->getReservePropCode() . "_VALUE" => $params['REZERV_MAX'],
							];
						}
						$mas = 'Y';
					}
				}
			}


			if (!empty($params['quantity_to']) && !empty($params['quantity_from'])) {

				foreach ($stores as $store) {
					$arFilter[1][] = [
						">=CATALOG_STORE_AMOUNT_" . $store->getId() => $params['quantity_from'],
						"<=CATALOG_STORE_AMOUNT_" . $store->getId() => $params['quantity_to'],
					];
				}
				$mas1 = 'Y';
			} else {
				if (empty($params['quantity_to']) && !empty($params['quantity_from'])) {
					foreach ($stores as $store) {
						$arFilter[1][] = [
							">=CATALOG_STORE_AMOUNT_" . $store->getId() => $params['quantity_from']
						];
					}
					$mas1 = 'Y';
				} else {
					if (empty($params['quantity_from']) && !empty($params['quantity_to'])) {
						foreach ($stores as $store) {
							$arFilter[1][] = [
								">=CATALOG_STORE_AMOUNT_" . $store->getId() => 0,
								"<=CATALOG_STORE_AMOUNT_" . $store->getId() => $params['quantity_to'],
							];
						}
						$mas1 = 'Y';
					}
				}
			}
		}
		if ($mas != 'Y') {
			unset($arFilter[0]);
		} else {
			$arFilter[0]['LOGIC'] = "OR";
		}
		if ($mas1 != 'Y') {
			unset($arFilter[1]);
		} else {
			$arFilter[1]['LOGIC'] = "OR";
		}

		return $arFilter;
	}

	/**
	 * Возвращает список ID элементов на основе параметров гетлиста,
	 * чтобы их можно было передать в фильтр компонента.
	 * Нужно для кастомной сортировки.
	 *
	 * @param array $filter
	 * @param array $sort
	 * @param int   $count
	 * @return array
	 */
	public static function getElementIdsForFilter(array $filter, array $sort = [], int $count = 16): array
	{
		$select = ['ID'];
		$nav = false;
		if ($count){
			$nav = ['nTopCount' => $count];
		}

		$iterator = CIBlockElement::GetList($sort, $filter, false, $nav, $select);
		$ids = [];
		while ($row = $iterator->Fetch()){
			$ids[] = $row['ID'];
		}

		return $ids;
	}

	/**
	 * @param $sortBy
	 * @param $orderBy
	 *
	 * @return array
	 */
	public static function prepareSortParams($sortBy, $orderBy): array
	{
		return ($sortBy && $orderBy) ? ['SORT_BY' => $sortBy, 'ORDER_BY' => $orderBy] : [];
	}

	/**
	 * Определим какая ссылка нам нужна
	 *
	 * @param $filterUrl
	 * @param $request
	 *
	 * @return bool
	 */
	public static function isNeedNotSefFilterUrl($filterUrl, $request): bool
	{
		$needNotSef = !Utils::haveLinkForUri($filterUrl);

		// ЧПУ ссылка если выбираем только бренд и только один
		if (static::isOnlyOneBrandInFilter($request)) {
			$needNotSef = false;
		}

		return $needNotSef;
	}

	public static function handleUrl($request)
	{
		if (Url::isFilterPage()) {
			if (
				!Utils::haveLinkForUri((new Uri($_SERVER['REQUEST_URI']))->deleteParams(['clear_cache'])->getPath())
				&& !static::isOnlyOneBrandInFilter($request)
			) {
                $currentPage = $GLOBALS['APPLICATION']->GetCurPage(false);
                $url = substr($currentPage, -1) === '/'
                    ? substr($currentPage, 0, -1)
                    : $currentPage;

				LocalRedirect($url, false, '301 Moved Permanently');
			}
		}
	}

	/**
	 * Вырежем поля не относящиеся к свойствам
	 *
	 * @param $request
	 *
	 * @return mixed
	 */
	public static function getClearedProperties($request)
	{
		foreach (['ajax', 'ACTIVE', 'SECTION_ID', 'price_from', 'price_to', 'ISCAT'] as $defaultParam) {
			unset($request[$defaultParam]);
		}

		return $request;
	}

	/**
	 * @param $request
	 *
	 * @return bool
	 */
	public static function isOnlyOneBrandInFilter($request): bool
	{
		$requestProperties = static::getClearedProperties($request);
		return (count($requestProperties) == 1 && count($requestProperties['brand_ref'] ?? []) == 1);
	}

	private static function translitPropValue(string $value): string
	{
		// Логика из комбокса
		$params = [
			'replace_space' => '_',
			'replace_other' => '_',
		];
		$value = explode('|', $value);
		$value = htmlspecialcharsex($value[0]);
		return ToLower(CUtil::translit($value, 'ru', $params));
	}

	public static function makeUriFromParams(Uri $sectionUrl, array $params): Uri
	{
		$helper = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getIblockPropertyHelper();

		$url = $sectionUrl->getUri();

		$urlParams = [];

		foreach ($params as $param) {
			if ($param['checks']) {
				if ($param['propId'] === 'stores') {
					$code = 'stores';

					foreach ($param['checks'] as $value) {
						$urlParams[] = $code . '[]=' . $value;
					}
				} else {
					$prop = $helper->getPropertyById($param['propId']);
					$code = strtolower($prop['CODE']);
					$code = str_replace('__', '_', $code);

					foreach ($param['checks'] as $valueId) {

						if ($prop['CODE'] == 'BRAND_REF') {
							$value = strtolower($valueId);
						} else {
							$value = $helper->getPropertyEnumById($prop, $valueId)['VALUE'];
							$value = static::translitPropValue($value);
						}

						$urlParams[] = $code . '[]=' . str_replace(' ', '_', $value);
					}
				}
			} elseif ($param['min'] || $param['max']) {

				if ($param['propId'] == 'REZERV') {
					if ($param['min']) {
						$urlParams[] = 'REZERV_MIN=' . $param['min'];
					}
					if ($param['max']) {
						$urlParams[] = 'REZERV_MAX=' . $param['max'];
					}
				} else {
					if (in_array($param['propId'], ['price', 'quantity'])) {
						$code = $param['propId'];
					} else {
						$prop = $helper->getPropertyById($param['propId']);
						$code = strtolower($prop['CODE']);
					}

					if ($param['min']) {
						$urlParams[] = $code . '_from=' . $param['min'];
					}
					if ($param['max']) {
						$urlParams[] = $code . '_to=' . $param['max'];
					}
				}
			}
		}

		$url .= '?' . implode('&', $urlParams);

		return new Uri($url);
	}

	/**
	 * @param int[] $compareGroupIds
	 * @return FilterPropHint[][]
	 */
	public static function getHintsForCompareGroups(array $compareGroupIds): array
	{
		$filter = [
			'=UF_COMPARE_GROUP' => $compareGroupIds,
		];

		$dbProps = HlBlockHelperRegistry::getInstance()
			->getByCode('CatalogFilterProps')
			->getElementsByFilter($filter, ['*']);

		$hints = [];
		foreach ($dbProps as $dbProp){
			$hints[$dbProp['UF_COMPARE_GROUP']][$dbProp['UF_COMPARE_GROUP_CODE']] = static::makePropHint($dbProp);
		}

		return $hints;
	}

	/**
	 * @param string[] $codes
	 * @return FilterPropHint[]
	 */
	public static function getHintsByPropCodes(array $codes): array
	{
		if (!$codes){
			return [];
		}

		$filter = [
			'=UF_PROP_CODE' => $codes,
		];

		$dbProps = HlBlockHelperRegistry::getInstance()
			->getByCode('CatalogFilterProps')
			->getElementsByFilter($filter, ['*']);

		$hints = [];
		foreach ($dbProps as $dbProp){
			$hints[$dbProp['UF_PROP_CODE']] = static::makePropHint($dbProp);
		}

		return $hints;
	}

	public static function getAllowedPropertiesForSearchPage(): array
	{
		$properties = PropertyTable::query()
			->addSelect('ID')
			->whereIn(
				'CODE',
				[
					'BRAND_REF',
					'F_AKCIJA',
				]
			)
			->fetchAll();

		return array_column($properties, 'ID');
	}

	private static function makePropHint(array $dbProp): FilterPropHint
	{
		$title = $dbProp['UF_HINT_TITLE'] ?: '';
		$text = $dbProp['UF_HINT_TEXT'] ?: '';
		$videoUrl = $dbProp['UF_HINT_VIDEO_URL'] ?: '';
		if ($videoUrl){
			$videoUrl = 'https://www.youtube.com/' . ltrim($videoUrl, '/');
		}
		return new FilterPropHint($title, $text, $videoUrl);
	}

	public static function executeKombox(
		Uri $uri,
		$sectionIds,
		array $getParams,
		bool $showComplects = false,
		bool $showAccessors = false,
		bool $archiveOnly = false,
		array $productIds = [],
		array $allowedProperties = []
	): Entities\Filter
	{
		if (!is_array($sectionIds)) {
			$sectionId = $sectionIds;
			$sectionIds = null;
		} else {
			$sectionId = null;
		}

		$managerFilter = static::initManagerFilter($getParams);
		$globalFilter = [];

		if (!$archiveOnly){
			$globalFilter['!PROPERTY_ARCHIVE_VALUE'] = 'Да';
		}

		if (!$showAccessors){
			$globalFilter['PROPERTY_ACSESSUAR_SORT'] = false;
		}

		if (!$showComplects){
			$globalFilter['PROPERTY_COMPLECT_MAIN_PRODUCT'] = false;
		}

		if ($productIds) {
			$globalFilter['ID'] = $productIds;
		}

		$GLOBALS['arrFilter'] = array_replace($globalFilter, $managerFilter);

		$params = [
			"IBLOCK_TYPE" => 'catalog',
			"IBLOCK_ID" => CATALOG_IBLOCK_ID,
			"FILTER_NAME" => 'arrFilter',
			"SECTION_ID" => $sectionId,
			"SECTION_IDS" => $sectionIds,
			"SECTION_CODE" => '',
			"HIDE_NOT_AVAILABLE" => 'N',
			"CACHE_TYPE" => 'A',
			"CACHE_TIME" => 36000000,
			"CACHE_GROUPS" => 'Y',
			"SAVE_IN_SESSION" => "N",
			"INCLUDE_JQUERY" => "Y",
			"MESSAGE_ALIGN" => "LEFT",
			"MESSAGE_TIME" => "0",
			"IS_SEF" => "N",
			"CLOSED_PROPERTY_CODE" => [],
			"CLOSED_OFFERS_PROPERTY_CODE" => [],
			"SORT" => "N",
			"FIELDS" => [],
			"PRICE_CODE" => PriceTypes::getPriceNamesForFilter(),
			"CONVERT_CURRENCY" => 'Y',
			"CURRENCY_ID" => 'RUB',
			"XML_EXPORT" => "Y",
			"SECTION_TITLE" => "NAME",
			"SECTION_DESCRIPTION" => "DESCRIPTION",
			'PAGE_URL' => $uri->getPath(),
			'EXTERNAL_PARAMS' => [
				'TARGET_URL' => $uri->getUri(),
				'IS_SEF' => true,
				'GET' => $getParams,
				'USER_GROUPS' => []
			],
			'ALLOWED_ITEMS_IDS' => $allowedProperties,
		];

		if ($uri->hasParams()){
			$componentResult = ComponentExecutor::executeComponent('kombox:filter', $params);
		} else {
			$componentResult = ComponentExecutor::executeComponentCached('kombox:filter', $params);
		}

		return new Entities\Filter($componentResult, $componentResult['RESULT_FILTER'], $uri, User::isManager());
	}
}
