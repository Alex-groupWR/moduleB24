<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sale\Fuser;
use Rusgeocom\Rusgeocom\Api\Exceptions\ApiException;
use Rusgeocom\Rusgeocom\Catalog\Entities\CatalogQueryParams;
use Rusgeocom\Rusgeocom\Catalog\Entities\ProductCollection;
use Rusgeocom\Rusgeocom\Catalog\Tables\ViewedProductsTable;
use Rusgeocom\Rusgeocom\Types\Image;
use Rusgeocom\Rusgeocom\Utils\User;

class ViewedProducts
{
	private const RECENT_PRODUCTS_COUNT = 6;

	public static function getArray(int $limit = 0, array $select = ['*']): array
	{
		return ViewedProductsTable::query()
			->setSelect($select)
			->setLimit($limit)
			->setOrder(['DATE_VIEW' => 'DESC'])
			->setFilter([
				'FUSER_ID' => Fuser::getId(),
			])->fetchAll();
	}

	public static function addItem(int $productId, int $fUserId): void
	{
		if (!$productId) {
			return;
		}

		$exist = ViewedProductsTable::getList([
			'select' => ['ID'],
			'filter' => [
				'PRODUCT_ID' => $productId,
				'FUSER_ID' => $fUserId,
			],
		])->fetch();

		if (!Catalog::checkExistsById($productId)) {
			throw new ApiException('Товар не существует');
		}

		if (!$exist) {
			ViewedProductsTable::add([
				'FUSER_ID' => $fUserId,
				'PRODUCT_ID' => $productId,
			]);
		} else {
			ViewedProductsTable::update($exist['ID'], [
				'DATE_VIEW' => new DateTime(),
			]);
		}
	}

	public static function moveToAuthorized($userId)
	{
		$items = ViewedProductsTable::getList([
			'select' => ['ID'],
			'filter' => [
				'FUSER_ID' => Fuser::getId(),
			],
		])->fetchAll();

		foreach (array_column($items, 'ID') as $id) {
			ViewedProductsTable::update($id, ['FUSER_ID' => Fuser::getIdByUserId($userId)]);
		}
	}

	public static function onBeforeUserLogin(&$fields)
	{
		if ($fields['LOGIN'] != 'dev') {
			Loader::includeModule('sale');
			$userId = User::getIdByLogin($fields['LOGIN']);
			static::moveToAuthorized($userId);
		}
	}

	public static function getProductIds(int $limit = 0): array
	{
		return array_column(static::getArray($limit, ['PRODUCT_ID']), 'PRODUCT_ID');
	}

	public static function makeViewedProducts(): ProductCollection
	{
		$productIds = ViewedProducts::getProductIds(static::RECENT_PRODUCTS_COUNT);

		$params = CatalogQueryParams::create()
			->disablePagination()
			->addFilter('ID', $productIds)
			->setSort(['ID' => $productIds])
			->setShowOutOfStock(true)
			->setShowAccessors(true)
			->setShowComplects(true);

		$catalogResult = Catalog::query($params);

		return $catalogResult->getProducts()->resizeDetailPictures([Image::SIZE_CATALOG_LIST])->disableImagesSlider();
	}
}
