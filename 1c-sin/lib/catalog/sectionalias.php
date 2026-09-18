<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use CIBlockElement;
use CIBlockSection;
use CModule;
use Rusgeocom\Rusgeocom\Utils\FileUtils;

class SectionAlias
{
	//region События
	public static function onAfterIBlockSectionAdd($fields)
	{
		static::updateRewriteRules($fields);
	}

	public static function onAfterIBlockSectionUpdate($fields)
	{
		static::updateRewriteRules($fields);
	}

	public static function onAfterIBlockSectionDelete($fields)
	{
		static::removeRewriteRules($fields);
	}

	//endregion

	public static function updateRewriteRules($fields)
	{
		if ($fields['IBLOCK_ID'] != CATALOG_IBLOCK_ID) {
			return;
		}
		FileUtils::removeDirectorys($_SERVER['DOCUMENT_ROOT'] . '/bitrix/cache/CurSection');
		CModule::IncludeModule("iblock");

		$file = $_SERVER["DOCUMENT_ROOT"] . '/rewrite/section.php';

		require $file;


		$arFilter = ['IBLOCK_ID' => CATALOG_IBLOCK_ID, 'ID' => $fields['ID']];
		$db_list = CIBlockSection::GetList([], $arFilter, true, ['UF_ALIASE']);

		$ar_result = $db_list->GetNext();

		$ar_result['UF_ALIASE'] = ltrim($ar_result['UF_ALIASE'], '/');

		if (!$ar_result['UF_ALIASE']) {
			return;
		}

		if (substr($ar_result['UF_ALIASE'], -1, 1) == '/') {
			$ar_result['UF_ALIASE'] = substr($ar_result['UF_ALIASE'], 0, strlen($ar_result['UF_ALIASE']) - 1);
		}

		if (substr($ar_result['UF_ALIASE'], 0, 1) != '/') {
			$ar_result['UF_ALIASE'] = '/' . $ar_result['UF_ALIASE'];
		}

		$sectionRewrite[$ar_result['ID']] = [
			'/catalog' . $ar_result['SECTION_PAGE_URL'],
			$ar_result['UF_ALIASE']
		];

		file_put_contents($file, '<?php $sectionRewrite = ' . var_export($sectionRewrite, true) . ';');
	}

	public static function removeRewriteRules($fields)
	{
		if ($fields['IBLOCK_ID'] != CATALOG_IBLOCK_ID) {
			return;
		}

		$file = $_SERVER["DOCUMENT_ROOT"] . '/rewrite/section.php';

		require $file;

		if (isset($sectionRewrite[$fields['ID']])) {
			unset($sectionRewrite[$fields['ID']]);
		}

		file_put_contents($file, '<?php $sectionRewrite = ' . var_export($sectionRewrite, true) . ';');
	}
}