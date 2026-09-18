<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Main\Entity\ReferenceField;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UserFieldTable;
use Logema\Utils\DataAccess\IblockHelper;
use Rusgeocom\Rusgeocom\Catalog\Tables\AdvertisingHistoryTable;
use Rusgeocom\Rusgeocom\Utils\HlBlockHelperRegistry;

use Rusgeocom\Rusgeocom\Utils\User;

class Advertising
{
	const IDENTIFICATION_FIELD = 'UF_LINK';
	const SHOW_LOCATION_SECTION = 'section';
	const SHOW_LOCATION_DETAIL = 'detail';

	protected static $statusListCached = [];

	/**
	 * @param $link
	 *
	 * @return array
	 */
	public static function getButtonList($link, $location): array
	{
		$target = static::getTarget($link);

		$result = [];

		foreach (static::getAdvertisingList() as $advertising) {

			if (!static::isButtonLocationEnabled($advertising, $location)){
				continue;
			}

			$advertisingBlock = $target[$advertising['ID']];

			if ($advertisingBlock) {
				$result[] = [
					'advertisingId' => intval($advertising['ID']),
					'tooltip' => $advertising['UF_TOOLTIP'],
					'icon' => $advertising['UF_ICON'],
					'statusId' => intval($advertisingBlock['UF_STATUS']),
					'comment' => $advertisingBlock['UF_COMMENT'],
					'modifiedDate' => $advertisingBlock['UF_MODIFIED_DATE'] ? $advertisingBlock['UF_MODIFIED_DATE']->format('d.m.Y') : '',
					'modifiedBy' => User::getAvailableName($advertisingBlock['UF_MODIFIED_BY'])
				];
			} else {
				$result[] = static::getDefaultBlockValues($advertising);
			}
		}

		return $result;
	}

	protected static function isButtonLocationEnabled(array $button, string $location = ''): bool
	{
		return !$location || $button['UF_' . strtoupper($location) . '_SHOW'] !== '0'; // Явно снята галочка
	}

	/**
	 * @param $advertising
	 *
	 * @return array
	 */
	protected static function getDefaultBlockValues($advertising): array
	{
		return [
			'advertisingId' => intval($advertising['ID']),
			'tooltip' => $advertising['UF_TOOLTIP'],
			'icon' => $advertising['UF_ICON'],
			'statusId' => intval(static::getStatusesFromDb()['DEFAULT']['id']),
			'comment' => '',
			'modifiedDate' => '',
			'modifiedBy' => '',
		];
	}

	/**
	 * @return array
	 */
	protected static function getAdvertisingList(): array
	{
		return HlBlockHelperRegistry::getInstance()
			->advertisingTypeList()
			->getElementsByFilter(['!UF_ICON' => false], [], 0, ['UF_SORT' => 'ASC']);
	}

	/**
	 * @param $link
	 *
	 * @return array
	 */
	protected static function getTarget($link): array
	{
		$target = [];

		$blocksResult = HlBlockHelperRegistry::getInstance()
			->advertisingBlocks()
			->getElementsByFilter([static::IDENTIFICATION_FIELD => $link]);

		foreach ($blocksResult as $advertisingBlock) {
			$target[$advertisingBlock['UF_ADVERTISING_TYPE']] = $advertisingBlock;
		}

		return $target;
	}

	/**
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getStatusList(): array
	{
		return array_values(static::getStatusesFromDb()['ALL']);
	}

	/**
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getStatusesFromDb(): array
	{
		if (!static::$statusListCached) {
			$hlbId = HlBlockHelperRegistry::getInstance()->getIdByCode('AdvertisingBlocks');
			$iterator = UserFieldTable::query()
				->addFilter('ENTITY_ID', 'HLBLOCK_' . $hlbId)
				->addFilter('FIELD_NAME', 'UF_STATUS')
				->addSelect('ID')
				->addSelect('FIELD_NAME')
				->exec();
			$fields = [];
			while ($row = $iterator->fetch()) {
				$fields[$row['ID']] = $row;
			}
			$dbStatuses = (new \CUserFieldEnum)->GetList([], ['USER_FIELD_ID' => array_column($fields, 'ID')]);
			while ($status = $dbStatuses->Fetch()) {
				$status = [
					'id' => intval($status['ID']),
					'code' => strtolower($status['XML_ID']),
					'value' => $status['VALUE']
				];

				static::$statusListCached['ALL'][$status['id']] = $status;

				if ($status['code'] == 'not_processed') {
					static::$statusListCached['DEFAULT'] = $status;
				}
			}
		}

		return static::$statusListCached;
	}

	/**
	 * @param string $link
	 * @param int $advertisingId
	 * @param array $fields
	 *
	 * @return array
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Throwable
	 */
	public static function update(string $link, int $advertisingId, array $fields, string $location): array
	{
		$advertisingBlocks = HlBlockHelperRegistry::getInstance()
			->advertisingBlocks();
		try {
			$elementToUpdate = $advertisingBlocks
				->getElementByFilter(
					[
						static::IDENTIFICATION_FIELD => $link,
						'UF_ADVERTISING_TYPE' => $advertisingId
					],
					['ID']
				);

			$advertisingBlockEntity = $advertisingBlocks->getEntityClass();

			if ($elementToUpdate) {
				$advertisingBlockEntity::update($elementToUpdate['ID'], $fields);
				$blockId = $elementToUpdate['ID'];
			} else {
				$result = $advertisingBlockEntity::add(
					$fields + [
						'UF_ADVERTISING_TYPE' => $advertisingId,
						static::IDENTIFICATION_FIELD => $link
					]
				);

				if (!$result->isSuccess()){
					throw new \Exception(implode('. ', $result->getErrorMessages()));
				}

				$blockId = $result->getId();
			}

			static::saveHistory($blockId, $fields['UF_STATUS']);

		} catch (\Throwable $exception) {
			throw $exception;
		}

		return static::getButtonList($link, $location);
	}

	private static function saveHistory(int $blockId, int $statusId): void
	{
		$fields = [
			'BLOCK_ID' => $blockId,
			'STATUS_ID' => $statusId,
		];
		$result = AdvertisingHistoryTable::add($fields);

		if (!$result->isSuccess()){
			throw new \Exception(implode('. ', $result->getErrorMessages()));
		}
	}

	/**
	 * @param string $link
	 * @param array $fields
	 *
	 * @return array
	 * @throws \Bitrix\Main\Db\SqlQueryException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Throwable
	 */
	public static function updateAll(string $link, array $fields, string $location): array
	{
		$advertisingBlocks = HlBlockHelperRegistry::getInstance()
			->advertisingBlocks();

		$elementsToUpdate = $advertisingBlocks
			->getElementsByFilter(
				[static::IDENTIFICATION_FIELD => $link],
				['ID', 'UF_ADVERTISING_TYPE']
			);

		try {
			$advertisingBlockEntity = $advertisingBlocks->getEntityClass();
			foreach ($elementsToUpdate as $element) {
				$advertisingBlockEntity::update($element['ID'], $fields);
				static::saveHistory($element['ID'], $fields['UF_STATUS']);
			}

			$advertisingTypeList = HlBlockHelperRegistry::getInstance()
				->advertisingTypeList()
				->getElementsByFilter([
					'!ID' => array_column($elementsToUpdate, 'UF_ADVERTISING_TYPE'),
					'!UF_ICON' => false
				]);

			foreach ($advertisingTypeList as $advertising) {

				if (!static::isButtonLocationEnabled($advertising, $location)){
					continue;
				}

				$result = $advertisingBlockEntity::add(
					$fields + [
						'UF_ADVERTISING_TYPE' => $advertising['ID'],
						static::IDENTIFICATION_FIELD => $link
					]
				);

				if (!$result->isSuccess()){
					throw new \Exception(implode('. ', $result->getErrorMessages()));
				}

				static::saveHistory($result->getId(), $fields['UF_STATUS']);
			}

		} catch (\Throwable $exception) {
			throw $exception;
		}

		return static::getButtonList($link, $location);
	}

	/**
	 * @param $statusId
	 * @param $comment
	 *
	 * @return array
	 */
	public static function prepareUpdatingFields($statusId, $comment): array
	{
		return [
			'UF_STATUS' => $statusId,
			'UF_COMMENT' => trim($comment),
			'UF_MODIFIED_BY' => User::getId() ?: null,
			'UF_MODIFIED_DATE' => new DateTime()
		];
	}

	/**
	 * Определим, выводить ли на текущей странице блок с рекламами
	 *
	 * @return bool
	 */
	public static function isShow($link): bool
	{
		foreach ($_GET as $param => $value) {
			if (strpos($param, 'f_') !== false) {
				return false;
			}
		}
		
		$uriPieces = array_filter(explode('/', $link), function ($a) {
			return boolval($a);
		});
		$lastParam = end($uriPieces);
		
		if ($lastParam == 'f') {
			return false;
		}

		return true;
	}

	/**
	 * @return string
	 */
	public static function prepareLink(): string
	{
		$ignoredTails = [
			'otzyvy',
			'optom'
		];

		$resultLink = \Rusgeocom\Rusgeocom\Utils\Url::clearAllGetParams($_SERVER['REQUEST_URI']);

		$resultLink = str_replace($ignoredTails, '', $resultLink);
		
		$resultLink = (substr($resultLink, -1) == '/') ? $resultLink : $resultLink . '/';
		
		return strval($resultLink);
	}

	public static function getHistoryForSections(array $sectionIds): array
	{
		$select = ['UF_ALIASE'];
		$sections = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getSectionsByIds($sectionIds, $select);
		foreach ($sections as $section){
			$urlMap[$section['ID']] = self::normalizeUrl($section['UF_ALIASE']);
		}

		return static::getHistoryByUrlMap($urlMap);
	}

	public static function getHistoryForProducts(array $productIds): array
	{
		$select = ['PROPERTY_ALIASE'];
		$products = IblockHelper::forIblock(CATALOG_IBLOCK_ID)->getElementsByIds($productIds, $select);
		$urlMap = [];
		foreach ($products as $product){
			$urlMap[$product['ID']] = self::normalizeUrl($product['PROPERTIES']['ALIASE']['VALUE']);
		}

		return static::getHistoryByUrlMap($urlMap);
	}

	public static function getHistoryByUrls(array $urls): array
	{
		$map = [];
		foreach ($urls as $url){
			$map[$url] = self::normalizeUrl($url);
		}

		return static::getHistoryByUrlMap($map);
	}

	public static function getHistoryByUrl(string $url): array
	{
		$url = self::normalizeUrl($url);
		return static::getHistoryByUrlMap([$url])[0] ?: [];
	}

	private static function normalizeUrl(string $url): string
	{
		return '/' . trim($url, '/') . '/';
	}

	/**
	 * @param array $urlMap
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	private static function getHistoryByUrlMap(array $urls): array
	{
		$urls = array_flip($urls);

		$statuses = static::getStatusesFromDb()['ALL'];
		$statusNames = [];
		foreach ($statuses as $status){
			$statusNames[$status['id']] = $status['value'];
		}

		$advTypes = static::getAdvertisingList();
		$feedNames = [];
		foreach ($advTypes as $type){
			$feedNames[$type['ID']] = $type['UF_FEED_NAME'];
		}

		$iterator = AdvertisingHistoryTable::query()
			->addSelect('ID')
			->addSelect('DATE')
			->addSelect('STATUS_ID')
			->addSelect('BLOCK.UF_LINK', 'URL')
			->addSelect('BLOCK.UF_ADVERTISING_TYPE', 'FEED_ID')
			->addFilter('BLOCK.UF_LINK', array_keys($urls))
			->addOrder('DATE', 'ASC')
			->registerRuntimeField('BLOCK', new ReferenceField(
				'BLOCK',
				HlBlockHelperRegistry::getInstance()->advertisingBlocks()->getEntityClass(),
				['=this.BLOCK_ID' => 'ref.ID'],
				['join_type' => 'LEFT']
			))
			->exec();

		$history = [];
		while ($row = $iterator->fetch()){
			$productId = $urls[$row['URL']];
			$history[$productId][$row['ID']] = [
				'STATUS' => $statusNames[$row['STATUS_ID']],
				'DATE' => $row['DATE']->format('d.m.Y'),
				'FEED' => $feedNames[$row['FEED_ID']],
			];
		}

		return $history;
	}
}
