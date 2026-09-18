<?php


namespace Rusgeocom\Rusgeocom\Catalog;


use Bitrix\Currency\CurrencyManager;
use CCatalog;
use CCurrencyLang;
use CIBlockElement;
use CIBlockPriceTools;
use CIBlockSection;
use CModule;
use CPrice;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Rusgeocom\Core\Utils\PriceUtils;
use Rusgeocom\Rusgeocom\Catalog\Services\PriceTypes;
use Rusgeocom\Rusgeocom\Utils\FileUtils;
use Rusgeocom\Rusgeocom\Utils\Settings;
use Rusgeocom\Rusgeocom\Utils\User;

class Price
{
	public const CURRENCY_RUB = 'RUB';
	public const WHOLESALE_PRICE_PREFIX = 'PRICE_WHOLESALE_';
	public const DISCOUNT_WITH_COUPON_CODE = 'USE_DISCOUNT_WITH_COUPON';
	public const PRICE_MODIFIER_CODE = 'PRICE_MODIFIER';
	public const MAX_WHOLESALE_LEVEL = 2;

	public static function onAfterIBlockElementUpdate(&$fields)
	{
		return static::notOnlyIBlockElementAfterSaveHandler($fields);
	}

	public static function onAfterIBlockElementAdd(&$fields)
	{
		return static::notOnlyIBlockElementAfterSaveHandler($fields);
	}

	public static function onPriceAdd($id, $fields)
	{
		return static::notOnlyIBlockElementAfterSaveHandler($id, $fields);
	}

	public static function onPriceUpdate($id, $fields)
	{
		return static::notOnlyIBlockElementAfterSaveHandler($id, $fields);
	}

	public static function onProductUpdate($id, $fields)
	{
		return static::notOnlyIBlockElementAfterSaveHandler($id, $fields);
	}

	public static function calcPercent(float $price, float $discount): int
	{
		if (!$price) {
			return 0;
		}

		return (int)round($discount * 100 / $price);
	}

	public static function compare(float $left, float $right): bool
	{
		return abs($left - $right) < 0.01;
	}

	/**
	 * @todo Требует разделения на методы под каждое событие
	 * @deprecated
	 */
	public static function notOnlyIBlockElementAfterSaveHandler($arg1, $arg2 = false)
	{
		$url = '';

		$aliases = isset($arg1['PROPERTY_VALUES'][35]) ? Arr::wrap($arg1['PROPERTY_VALUES'][35]) : [];
		foreach ($aliases as $val) {
			$url = is_array($val) ? $val['VALUE'] : $val;
			break;
		}
		//pr($url,1);


		if ($url != '') {


			$arSection = CIBlockSection::GetList(
				[],
				[

					'IBLOCK_ID' => 2,
					'ACTIVE' => "Y",
					"ID" => $arg1['IBLOCK_SECTION'],
				],
				false,
				["ID", "NAME", "UF_ALIASE"],
				false
			)->Fetch();

			//pr($arSection,1);
			$PARENT_URL = '';
			if (isset($arSection['UF_ALIASE']) && $arSection['UF_ALIASE'] != '') {
				$PARENT_URL = $arSection['UF_ALIASE'];
			}


			//	clearCache($url,$PARENT_URL);
		}


		static $use_handlerf = true;

		if ($use_handlerf) {
			CModule::IncludeModule("iblock");
			CModule::IncludeModule("catalog");

			$ELEMENT_ID = false;
			$IBLOCK_ID = false;
			$Pricesd = true;
			$OFFERS_IBLOCK_ID = false;
			$OFFERS_PROPERTY_ID = false;

			if (is_array($arg2) && $arg2["PRODUCT_ID"] > 0) {
				//Get iblock element
				$rsPriceElement = CIBlockElement::GetList(
					[],
					[
						"ID" => $arg2["PRODUCT_ID"],
					],
					false,
					false,
					["ID", "IBLOCK_ID"]
				);
				if ($arPriceElement = $rsPriceElement->Fetch()) {
					$arCatalog = CCatalog::GetByID($arPriceElement["IBLOCK_ID"]);
					if (is_array($arCatalog)) {
						//Check if it is offers iblock
						if ($arCatalog["OFFERS"] == "Y") {
							//Find product element
							$rsElement = CIBlockElement::GetProperty(
								$arPriceElement["IBLOCK_ID"],
								$arPriceElement["ID"],
								"sort",
								"asc",
								["ID" => $arCatalog["SKU_PROPERTY_ID"]]
							);
							$arElement = $rsElement->Fetch();
							if ($arElement && $arElement["VALUE"] > 0) {

								$ELEMENT_ID = $arElement["VALUE"];
								$IBLOCK_ID = $arCatalog["PRODUCT_IBLOCK_ID"];
								$OFFERS_IBLOCK_ID = $arCatalog["IBLOCK_ID"];
								$OFFERS_PROPERTY_ID = $arCatalog["SKU_PROPERTY_ID"];
							}
						} //or iblock which has offers
						elseif ($arCatalog["OFFERS_IBLOCK_ID"] > 0) {
							$ELEMENT_ID = $arPriceElement["ID"];
							$IBLOCK_ID = $arPriceElement["IBLOCK_ID"];
							$OFFERS_IBLOCK_ID = $arCatalog["OFFERS_IBLOCK_ID"];
							$OFFERS_PROPERTY_ID = $arCatalog["OFFERS_PROPERTY_ID"];
						} //or it's regular catalog
						else {
							$ELEMENT_ID = $arPriceElement["ID"];
							$IBLOCK_ID = $arPriceElement["IBLOCK_ID"];
							$OFFERS_IBLOCK_ID = false;
							$OFFERS_PROPERTY_ID = false;
						}
					}
				}

			} elseif (is_array($arg1) && $arg1["ID"] > 0 && $arg1["IBLOCK_ID"] > 0) {
				//Check if iblock has offers
				$arOffers = CIBlockPriceTools::GetOffersIBlock($arg1["IBLOCK_ID"]);
				if (is_array($arOffers)) {
					$ELEMENT_ID = $arg1["ID"];
					$IBLOCK_ID = $arg1["IBLOCK_ID"];
					$OFFERS_IBLOCK_ID = $arOffers["OFFERS_IBLOCK_ID"];
					$OFFERS_PROPERTY_ID = $arOffers["OFFERS_PROPERTY_ID"];
				}

			}
			if ($arg2['PRICE'] != '') {

				$ddd = CIBlockElement::GetList(
					['SORT' => 'asc'],
					[
						"IBLOCK_ID" => 3,
						"ID" => $arg2['PRODUCT_ID'],
					],
					false,
					false,
					["ID", "IBLOCK_ID", "PROPERTY_CHECK", "PROPERTY_CML2_LINK"]
				);
				while ($arddd = $ddd->Fetch()) {
					$yyyy = $arddd;
				}


			}
			if ($yyyy['IBLOCK_ID'] == 3) {

				//foreach($arg1['PROPERTY_VALUES']['27'] as $vals){
				$ELEMENT_ID = $yyyy['PROPERTY_CML2_LINK_VALUE'];
				// }


				$OFFERS_IBLOCK_ID = 3;
				$OFFERS_PROPERTY_ID = 27;


				$rsPricess = CPrice::GetList(
					[],
					[
						"PRODUCT_ID" => $ELEMENT_ID,
					]
				);
				while ($arPrices = $rsPricess->Fetch()) {
					$rices_ID = $arPrices['ID'];
					//if($arPrices['PRICE']>0){
					//	$Pricesd=false;


					$prricc = explode('.', $arPrices['PRICE']);
					if ($prricc[0] == $arg2['PRICE']) {
						$Pricesd = false;

					}
					// }

				}
				if ($Pricesd) {
					$no = false;
					$rsOffers = CIBlockElement::GetList(
						['SORT' => 'asc'],
						[
							"IBLOCK_ID" => $OFFERS_IBLOCK_ID,
							"PROPERTY_" . $OFFERS_PROPERTY_ID => $ELEMENT_ID,
						],
						false,
						false,
						["ID", "PROPERTY_CHECK"]
					);
					while ($arOffer = $rsOffers->Fetch()) {

						if ($arOffer['PROPERTY_CHECK_VALUE'] != 'Да') {
							$arProductI[] = $arOffer['ID'];
							$no = true;
						}
					}
					$arProductID = $arProductI[0];
					/*if($no){
						AddMessage2Log(var_export('ELEMENT_ID '.$ELEMENT_ID.'  pr '.$arg2['PRICE'].'= ', true));

						 $use_handlerf = false;
						 $arFieldsff = Array(
										"PRODUCT_ID" => (int)$ELEMENT_ID,
										"CATALOG_GROUP_ID" => 1,
										"PRICE" =>intval($arg2['PRICE']),
										"CURRENCY" => $arg2['CURRENCY'],
										//"QUANTITY_FROM" => 1,
										//"QUANTITY_TO" =>10
									);
						 $res = CPrice::GetList(
                              array(),
                              array(
                                 "PRODUCT_ID" => $ELEMENT_ID,
                                 "CATALOG_GROUP_ID" => 1
                              )
                           );
                           if ($rices = $res->Fetch())
                              {

                             $rrd=CPrice::Update($rices["ID"], $arFieldsff, false);

                              }
                           else
                              {

                            CPrice::Add($arFieldsff);


                              }
							//CPrice::SetBasePrice($ELEMENT_ID,intval($arPrice['PRICE']).'.00',$arPrice['CURRENCY']);
							$use_handlerf = true;
					 //}
					}*/
				}
			}
		}


		if (!empty($ELEMENT_ID)) {
			$PROPERTY_ALIASE_VALUE = '';
			$rsElements = CIBlockElement::GetList(
				[],
				[
					'IBLOCK_ID' => 2,
					'ID' => $ELEMENT_ID
				],
				false,
				false,
				[]
			);

			while ($arElementg = $rsElements->GetNext()) {
				if ($arElementg["IBLOCK_SECTION_ID"] != '3230' && $arElementg["IBLOCK_SECTION_ID"] != '2955' && $arElementg["IBLOCK_SECTION_ID"] != '') {

					$res = CIBlockSection::GetByID($arElementg["IBLOCK_SECTION_ID"]);
					if ($ar_res = $res->GetNext()) {
						if ($ar_res["IBLOCK_SECTION_ID"] != '3230' && $ar_res["IBLOCK_SECTION_ID"] != '2955' && $ar_res["IBLOCK_SECTION_ID"] != '') {
							$arFilter = [
								'IBLOCK_ID' => 2,
								"ID" => $ar_res['IBLOCK_SECTION_ID']
							]; // выберет потомков без учета активности
							$rsSect = CIBlockSection::GetList([], $arFilter, false, ["UF_ALIASE"]);
							while ($arSect = $rsSect->GetNext()) {

								$linck2 = ltrim($arSect['UF_ALIASE'], '/');
							}
						} elseif ($ar_res['ID'] != '') {
							$arFilter = [
								'IBLOCK_ID' => 2,
								"ID" => $ar_res['ID']
							]; // выберет потомков без учета активности
							$rsSect = CIBlockSection::GetList([], $arFilter, false, ["UF_ALIASE"]);
							while ($arSect = $rsSect->GetNext()) {

								$linck2 = ltrim($arSect['UF_ALIASE'], '/');
							}
						}
					}

					$arFilterf = [
						'IBLOCK_ID' => 2,
						"ID" => $arElementg["IBLOCK_SECTION_ID"]
					]; // выберет потомков без учета активности
					$rsSect = CIBlockSection::GetList([], $arFilterf, false, ["UF_ALIASE"]);
					while ($arSect = $rsSect->GetNext()) {

						$linck3 = ltrim($arSect['UF_ALIASE'], '/');
					}
				}
				$PROPERTY_ALIASE_VALUE = CIBlockElement::GetProperty(2, $ELEMENT_ID, [], ["CODE" => "ALIASE"])->Fetch();
				if ($PROPERTY_ALIASE_VALUE['VALUE'] == '' && $arElementg['ID'] == $ELEMENT_ID) {
					$linck = ltrim($arElementg['CODE'], '/');

				} elseif ($PROPERTY_ALIASE_VALUE['VALUE'] != '' && $arElementg['ID'] == $ELEMENT_ID) {
					$linck = ltrim($PROPERTY_ALIASE_VALUE['VALUE'], '/');

				}
				// AddMessage2Log(var_export('arProductI  '.$linck.'= '.print_r($PROPERTY_ALIASE_VALUE, true), true));
			}
			$patchesd = [
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru',

				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/catalog',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/catalog',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/catalog',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/catalog',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/catalog',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/catalog',

				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/kontrolno-izmeritelnyie-priboryi',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/kontrolno-izmeritelnyie-priboryi',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/kontrolno-izmeritelnyie-priboryi',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/kontrolno-izmeritelnyie-priboryi',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/kontrolno-izmeritelnyie-priboryi',
				$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/kontrolno-izmeritelnyie-priboryi',
			];
			foreach ($patchesd as $p) {

				array_map("unlink", glob($p . '/index*'));

			}
			$linck01 = 'ovelty';
			$linck02 = 'hits';
			$linck00 = 'sale.html';
			$linck0 = 'search';
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/' . $linck01);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/' . $linck01);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/' . $linck01);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/' . $linck01);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/' . $linck01);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/' . $linck01);

			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/' . $linck02);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/' . $linck02);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/' . $linck02);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/' . $linck02);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/' . $linck02);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/' . $linck02);

			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/' . $linck0);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/' . $linck0);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/' . $linck0);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/' . $linck0);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/' . $linck0);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/' . $linck0);

			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/' . $linck00);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/' . $linck00);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/' . $linck00);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/' . $linck00);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/' . $linck00);
			FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/' . $linck00);
			if ($linck != '' && isset($linck)) {
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/' . $linck);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/' . $linck);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/' . $linck);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/' . $linck);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/' . $linck);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/' . $linck);
			}

			if ($linck2 != '' && isset($linck2)) {
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/' . $linck2);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/' . $linck2);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/' . $linck2);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/' . $linck2);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/' . $linck2);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/' . $linck2);
			}
			if ($linck3 != '' && isset($linck3)) {
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru/' . $linck3);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru/' . $linck3);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru/' . $linck3);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rostov.rusgeocom.ru/' . $linck3);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/nsk.rusgeocom.ru/' . $linck3);
				FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ekb.rusgeocom.ru/' . $linck3);
			}

		}


	}

	//TODO: Перевести на стандартные рельсы и перенести в хелпер
	public static function clearElementCacheForCurrency($pid, $url)
	{
		$domains = [
			'www.rusgeocom.ru',
			'spb.rusgeocom.ru',
			'ivanovo.rusgeocom.ru',
			'rostov.rusgeocom.ru',
			'ekb.rusgeocom.ru',
			'nsk.rusgeocom.ru'
		];
		// $path = '/var/www/krasnov/data/www/bitrix.rusgeocom.ru.new/bitrix/html_pages/';
		$path = '/home/bitrix/ext_www/rusgeocom.ru/bitrix/html_pages/';

		$url = ltrim($url, '/');
		$url = rtrim($url, '/');


		$arChain = \CIBlockSection::GetNavChain(false, $pid, ["ID"]);
		foreach ($domains as $domain) {
			if (file_exists($path . $domain . '/' . $url . '/index@.html'))
				unlink($path . $domain . '/' . $url . '/index@.html');

			foreach ($arChain->arResult as $pathItem) {

				$aarSection = CIBlockSection::GetList(
					["SORT" => 'ASC'],
					[
						"IBLOCK_ID" => 2,
						"ID" => $pathItem['ID'],
						"ACTIVE" => "Y",
						"!UF_TMPL" => 14
					],
					false,
					["ID", "NAME", "UF_ALIASE"],
					false
				)->Fetch();

				if (isset($aarSection['UF_ALIASE']) && $aarSection['UF_ALIASE'] != '') {

					$url_s = $aarSection['UF_ALIASE'];
					$url_s = ltrim($url_s, '/');
					$url_s = rtrim($url_s, '/');
					// pr($path.$domain.'/'.$url_s.'/index@.html');
					if (file_exists($path . $domain . '/' . $url_s . '/index@.html'))
						unlink($path . $domain . '/' . $url_s . '/index@.html');
				}
			}
			if (file_exists($path . $domain . '/sale.html/index@.html'))
				unlink($path . $domain . '/sale.html/index@.html');
			if (file_exists($path . $domain . '/hits/index@.html'))
				unlink($path . $domain . '/hits/index@.html');
			if (file_exists($path . $domain . '/novelty/index@.html'))
				unlink($path . $domain . '/novelty/index@.html');
		}
	}

	//TODO: Перевести на стандартные рельсы и перенести в хелпер
	public static function clearCacheWithCurrency($currency, $arFields)
	{
		CModule::IncludeModule("iblock");
		$rsElements = CIBlockElement::GetList(
			[],
			[
				'IBLOCK_ID' => 2,
				'ACTIVE' => Y,
				'CATALOG_CURRENCY_1' => $currency,
			],
			false,
			false,
			['ID', 'IBLOCK_SECTION_ID', 'PROPERTY_ALIASE']
		);


		while ($arElement = $rsElements->GetNext()) {

			if (isset($arElement['PROPERTY_ALIASE_VALUE']) && $arElement['PROPERTY_ALIASE_VALUE'] != '') {
				Price::clearElementCacheForCurrency($arElement['IBLOCK_SECTION_ID'],
					$arElement['PROPERTY_ALIASE_VALUE']);
			}

		}


	}

	/**
	 * Цена в рублях для элемента каталога из гетлиста
	 *
	 * @param array $product
	 * @return int
	 */
	public static function getBasePriceForProduct(array $product): int
	{
		if (!$product['CATALOG_PRICE_1']){
			return 0;
		}

		return static::convertToBaseCurrency($product['CATALOG_PRICE_1'], $product['CATALOG_CURRENCY_1']);
	}

	public static function convertToBaseCurrency(float $price, string $currency): int
	{
		if ($currency == self::CURRENCY_RUB){
			return $price;
		}

		$resultPrice = \CCurrencyRates::ConvertCurrency($price, $currency, self::CURRENCY_RUB);
		return (int)floor($resultPrice);
	}

	/**
	 * Возвращает цену в формате 123 456 ₽
	 *
	 * @param float $value (не уверен, что всегда float, пока без типа)
	 * @param bool $useNbsp Выводить ли с неразрывным пробелом
	 * @return string
	 */
	public static function format($value, bool $useNbsp = false): string
	{
		$defaultCurrency = CurrencyManager::getBaseCurrency();
		return CCurrencyLang::CurrencyFormat(intval($value), $defaultCurrency, false)
			. ($useNbsp ? "\u{00a0}" : ' ') . '₽';
	}

	public static function mailFormat($value): string
	{
		return $value ? number_format($value, 0, ',', ' ') . ' ₽' : '';
	}

	public static function fillPriceForProduct(array &$product, array $mainOffer = [])
	{
		$price = 0;
		$oldPrice = 0;
		$basePriceType = PriceTypes::getBasePriceType();
		$currency = null;

		$priceType = PriceTypes::getPriceTypeForUser(User::getId(), User::loggedForCheckout()) ?? $basePriceType;

		$isWholesalePrice = Str::startsWith($priceType->getName(), PriceTypes::WHOLESALE_PRICE_CODE_PREFIX);
		if ($isWholesalePrice) {
			//Пока заменяем обратно на базовую цену
			$priceType = $basePriceType;
		}

		$actualProduct = $mainOffer ?: $product;
		$actualPrices = $actualProduct['PRICES'] ?? [];
		if ($actualPrices) {
			//Вынести метод из хелпера в этот класс
			$actualPrice = null;
			if (!$priceType->isEqual($basePriceType)) {
				$typeId = $priceType->getId();
				$actualPrice = $product['BASE_PRICES'][$typeId] ?? ($actualPrices[$typeId] ?? null);
			}

			$actualPrice = ($actualPrice ?? $actualPrices[$basePriceType->getId()]) ?? [];

			$discountValue = $actualPrice['DISCOUNT_VALUE_VAT'] ?: $actualPrice['DISCOUNT_VALUE'];
			if ($discountValue) {
				$price = static::convertToBaseCurrency($discountValue, $actualPrice['CURRENCY']);
				$currency = $actualPrice['CURRENCY'];
			}
			$value = $actualPrice['VALUE_VAT'] ?: $actualPrice['VALUE'];
			if ($value) {
				$oldPrice = static::convertToBaseCurrency($value, $actualPrice['CURRENCY']);
			}
		} elseif (
			isset($actualProduct[$priceType->getPriceProperty()])
			|| isset($actualProduct[$basePriceType->getPriceProperty()])
		) {
			$currentPrice = isset($actualProduct[$priceType->getPriceProperty()])
				? $priceType
				: $basePriceType;

			$price = static::convertToBaseCurrency(
				$actualProduct[$currentPrice->getPriceProperty()],
				$currency = $actualProduct[$currentPrice->getCurrencyProperty()]
			);
			$oldPrice = $price;
		}

		if ($product['PROPERTIES']['PRICE_OLD']){
			$oldPrice = $product['PROPERTIES']['PRICE_OLD'];
		}

		if ($product['PROPERTIES']['ON_REQUEST'] || $product['PROPERTIES']['PRICE_ON_REQUEST']){
			$product['PRICE'] = 0;
			$product['PRICE_FORMATTED'] = 'по запросу';
			$product['OLD_PRICE'] = 0;
			$product['OLD_PRICE_FORMATTED'] = '';
		}
		else{
			$product['PRICE'] = $price;
			$product['PRICE_FORMATTED'] = $price ? static::format($price) : 'по запросу';
			$product['OLD_PRICE'] = $oldPrice == $price ? 0 : $oldPrice;
			$product['OLD_PRICE_FORMATTED'] =  $oldPrice > $price ? static::format($oldPrice) : '';
		}

		if ($product["PROPERTIES"]["PRICE_FROM"]["VALUE"]){
			$product['PRICE_FROM'] = preg_replace('/[^0-9]/', '', $product["PROPERTIES"]["PRICE_FROM"]["VALUE"]) ?: 0;
			$product['PRICE_FROM_FORMATTED'] = $product['PRICE_FROM'] ? ('от ' . static::format($product['PRICE_FROM'])) : '';
		}

		if ($currency) {
			$product['CURRENT_CURRENCY'] = $currency;
		}
	}

	public static function getUserPriceForProduct(array $product): ?int
	{
		return static::tryExtractFromBasePrices(
			$product, PriceTypes::getIdByName(PriceTypes::USER_PRICE_CODE)
		);
	}

	public static function getCompanyPriceForProduct(array $product): ?int
	{
		return static::tryExtractFromBasePrices(
			$product, PriceTypes::getIdByName(PriceTypes::COMPANY_PRICE_CODE)
		);
	}

	private static function tryExtractFromBasePrices(array $product, int $priceTypeId): ?int
	{
		if (!isset($product['BASE_PRICES'])) {
			return static::getPriceForProduct($priceTypeId, $product);
		}

		if (!isset($product['BASE_PRICES'][$priceTypeId])) {
			return null;
		}

		return static::getPriceForProduct(
			$priceTypeId,
			['PRICES' => [$priceTypeId => $product['BASE_PRICES'][$priceTypeId]]]
		);
	}

	public static function getWholesalePrices(array $product): array
	{
		$wholesalePrices = [];

		$priceSource = $product['BASE_PRICES'] ?? ($product['PRICES'] ?? []);
		for ($level = 1; $level <= static::MAX_WHOLESALE_LEVEL; $level++) {
			$priceName = PriceTypes::getWholesalePriceNameForLevel($level);
			$priceType = PriceTypes::getByName($priceName);
			if (!$priceType) {
				continue;
			}

			$currentPrice = $priceSource[$priceType->getId()] ?? [];
			if ($currentPrice) {
				$wholesalePrices[$level] = [
					'PRICE' => static::convertToBaseCurrency(
						$currentPrice['VALUE'],
						$currentPrice['CURRENCY']
					),
					'QUANTITY' => $currentPrice['QUANTITY_FROM'],
				];
			}
		}

		return $wholesalePrices;
	}

	public static function getPriceForProduct(int $priceTypeId, array $product, array $mainOffer = []): ?int
	{
		$actualProduct = $mainOffer ?: $product;

		if (!isset($actualProduct['PRICES'][$priceTypeId])) {
			//попробуем заполнить
			$priceType = PriceTypes::getById($priceTypeId);
			return $priceType && isset($actualProduct[$priceType->getPriceProperty()])
				? static::convertToBaseCurrency(
					$actualProduct[$priceType->getPriceProperty()],
					$actualProduct[$priceType->getCurrencyProperty()]
				)
				: null;
		}

		return static::convertToBaseCurrency(
			$actualProduct['PRICES'][$priceTypeId]['VALUE'],
			$actualProduct['PRICES'][$priceTypeId]['CURRENCY']
		);
	}

	//TODO: Перевести на стандартные рельсы и перенести в хелпер
	public static function removeDirectory($dir)
	{
		if ($objs = glob($dir . "/*")) {
			foreach ($objs as $obj) {
				is_dir($obj) ? self::removeDirectory($obj) : unlink($obj);
			}
		}
		rmdir($dir);
	}

	//TODO: Перевести на стандартные рельсы и перенести в хелпер
	public static function clearCache($path, $parent_url)
	{
		//$_SERVER["DOCUMENT_ROOT"] = "/var/www/krasnov/data/www/bitrix.rusgeocom.ru";
		$_SERVER["DOCUMENT_ROOT"] = "/home/bitrix/ext_www/rusgeocom.ru";
		$DOCUMENT_ROOT = $_SERVER["DOCUMENT_ROOT"];
		$patches = [
			$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/rusgeocom.ru',
			$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/www.rusgeocom.ru',
			$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/spb.rusgeocom.ru',
			$_SERVER['DOCUMENT_ROOT'] . '/bitrix/html_pages/ivanovo.rusgeocom.ru',
		];


		$path = ltrim($path, '/');
		$parent_path = trim($parent_url, "/");


		foreach ($patches as $p) {
			//pr(escapeshellarg($p.'/'.$path),1);
			if (is_dir($p . '/' . $path)) {

				Price::removeDirectory($p . '/' . $path);
			}
		}


		if ($parent_path != '') {
			foreach ($patches as $p) {
				//pr($p.'/'.$parent_path.'/index*',1);
				array_map("unlink", glob($p . '/' . $parent_path . '/index*'));
				//unlink($p.'/'.$parent_path.'/index*');
			}
		}


		BXClearCache(true, "/s1/rusgeocom/catalog.section_sort/");
		//BXClearCache(true, "/s1/bitrix/catalog.element/");
		BXClearCache(true, "/s1/bitrix/catalog.section/");

	}

	public static function isAuthorizedDiscountForCurrentUser(): bool
	{
		$priceType = PriceTypes::getPriceTypeForUser(User::getId(), User::loggedForCheckout());
		return PriceTypes::hasDiscountForAuthorized($priceType);
	}

	public static function getPriceForSplit(int $price, int $parts = 4, bool $roundUp = false): int
	{
		return $roundUp ? (int)ceil($price / $parts) : (int)floor($price / $parts);
	}

	public static function calculateVat(float $price): float
	{
		return $price * (Settings::getVatRate() / 100);
	}

	// TODO вынести в пакет
	public static function discountPercent(int $price, int $discountPercent, bool $allowMarkup = false): int
	{
		if ($price <= 0 || $discountPercent > 100) {
			return 0;
		}

		if (!$allowMarkup && $discountPercent < 0) {
			return 0;
		}

		return PriceUtils::round($price * ((100 - $discountPercent) / 100));
	}
}