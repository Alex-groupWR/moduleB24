<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use CIBlockElement;
use CModule;

class Alias
{
	//region События
	public static function onAfterIBlockElementAdd($fields)
	{
		static::updateRewriteRules($fields);
	}

	public static function onAfterIBlockElementUpdate($fields)
	{
		static::updateRewriteRules($fields);
	}

	public static function onAfterIBlockElementDelete($fields)
	{
		static::removeRewriteRules($fields);
	}
	//endregion

	public static function updateRewriteRules($fields)
	{

		if ($fields['IBLOCK_ID'] != CATALOG_IBLOCK_ID) {
			return;
		}


		CModule::IncludeModule("iblock");
		CModule::IncludeModule("catalog");


		$file = $_SERVER["DOCUMENT_ROOT"] . '/rewrite/element.php';

		require $file;

		$obj = CIBlockElement::GetList(
			["SORT" => "ASC"], [
			'IBLOCK_ID' => $fields['IBLOCK_ID'],
			'ID' => $fields['ID'],
		], false, false, [
				'ID',
				'CODE',
				'SECTION_ID',
				'DETAIL_PAGE_URL',
				'PROPERTY_ALIASE',
			]
		);

		$ob = $obj->GetNextElement();

		$res = $ob->GetFields();

		if (empty($res)) {
			return;
		}

		$alias = ltrim($res['PROPERTY_ALIASE_VALUE'], '/');

		if (!$alias) {
			return;
		}

		if (substr($alias, -1, 1) == '/') {
			$alias = substr($alias, 0, strlen($alias) - 1);
		}

		if (substr($alias, 0, 1) != '/') {
			$alias = '/' . $alias;
		}
		$tt = explode('/products/', $alias);
		if ($tt[1] != '') {
			return;
		}

		$elementRewrite[$fields['ID']] = [
			'/catalog' . $res['DETAIL_PAGE_URL'],
			$alias,
			$res['IBLOCK_SECTION_ID'],
		];


		file_put_contents($file, '<?php $elementRewrite= ' . var_export($elementRewrite, true) . ';');
	}

	public static function removeRewriteRules($fields)
	{
		if ($fields['IBLOCK_ID'] != CATALOG_IBLOCK_ID) {
			return;
		}

		CModule::IncludeModule("iblock");
		CModule::IncludeModule("catalog");


		$file = $_SERVER["DOCUMENT_ROOT"] . '/rewrite/element.php';

		require $file;

		if (isset($elementRewrite[$fields['ID']])) {
			unset($elementRewrite[$fields['ID']]);
		}

		file_put_contents($file, '<?php $elementRewrite= ' . var_export($elementRewrite, true) . ';');
	}
}