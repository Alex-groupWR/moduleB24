<?php

namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;
use Bitrix\Sale\Fuser;
use Rusgeocom\Rusgeocom\Catalog\Services\WishlistLogService;
use Rusgeocom\Rusgeocom\Catalog\Tables\WishlistTable;
use Rusgeocom\Rusgeocom\Utils\User;

class Wishlist
{
	public static function getArray(array $select = ['*'], ?int $fuserId = null): array
	{
		return WishlistTable::getList([
			'select' => $select,
			'filter' => [
				'FUSER_ID' => $fuserId ?? Fuser::getId(),
			]
		])->fetchAll();
	}

	public static function addItem($id): void
	{
		if (!$id) {
			return;
		}

		$fuserId = Fuser::getId();
		$exist = !!WishlistTable::getList([
			'select' => ['ID'],
			'filter' => [
				'PRODUCT' => $id,
				'FUSER_ID' => $fuserId,
			]
		])->fetch();

		if (!$exist && Catalog::checkExistsById($id)) {
			$result = WishlistTable::add([
				'FUSER_ID' => $fuserId,
				'PRODUCT' => $id,
			]);

			if ($result->isSuccess()) {
				WishlistLogService::onUpdate($fuserId, static::getProductIds());
			}
		}
	}

	public static function getItemRowIdById($id)
	{
		return WishlistTable::getList([
			'select' => ['ID'],
			'filter' => [
				'PRODUCT' => $id,
				'FUSER_ID' => Fuser::getId()
			]
		])->fetch()['ID'];
	}

	/**
	 * Отчищает все товары в избранном
	 *
	 * @return void
	 */
	public static function clear(): void
	{
		static::deleteByIds(static::getProductIds());
	}

	/**
	 * Отчищает все товары в избранном
	 *
	 * @return void
	 */
	public static function deleteByIds(array $productIds): void
	{
		foreach ($productIds as $itemId) {
			static::deleteItem($itemId);
		}
		if (!static::getProductIds()) {
			WishlistLogService::onAfterClear(Fuser::getId());
		}
	}

	public static function deleteItem($id): void
	{
		$rowId = static::getItemRowIdById($id);

		if ($rowId) {
			$result = WishlistTable::delete($rowId);

			if ($result->isSuccess()) {
				WishlistLogService::onUpdate(Fuser::getId(), static::getProductIds());
			}
		}
	}

	public static function checkFavoriteById($id): bool
	{
		return boolval(static::getItemRowIdById($id));
	}

	/**
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getCount(): int
	{
		return ElementTable::getCount([
			'IBLOCK_ID' => CATALOG_IBLOCK_ID,
			'ID' => static::getProductIds() ?: -1,
			'ACTIVE' => 'Y'
		]) ?: 0;
	}

	public static function moveToAuthorized($userId, ?int $fuserId = null): void
	{
		$oldFuserId = $fuserId ?? Fuser::getId();
		$newFuserId = Fuser::getIdByUserId($userId);

		if ($oldFuserId === $newFuserId) {
			return;
		}

		$items = WishlistTable::getList([
			'select' => ['ID'],
			'filter' => [
				'FUSER_ID' => $oldFuserId,
			]
		])->fetchAll();

		foreach (array_column($items, 'ID') as $id) {
			WishlistTable::update($id, ['FUSER_ID' => $newFuserId]);
		}

		$oldProductIds = static::getProductIds($oldFuserId);
		if ($oldProductIds) {
			WishlistLogService::onUpdate($oldFuserId, $oldProductIds);
		} else {
			WishlistLogService::onAfterClear($oldFuserId);
		}

		if ($newProductIds = static::getProductIds($newFuserId)) {
			WishlistLogService::onUpdate($newFuserId, $newProductIds);
		}
	}

	public static function onBeforeUserLogin(&$arFields): void
	{
		if ($arFields['LOGIN'] != 'dev') {
		    Loader::includeModule('sale');
			$userId = User::getIdByLogin($arFields['LOGIN']);
			Wishlist::moveToAuthorized($userId);
		}
	}

	/**
	 * @param int|null $fuserId
	 *
	 * @return int[]
	 */
	public static function getProductIds(?int $fuserId = null): array
	{
		$productIds = array_column(static::getArray(fuserId: $fuserId), 'PRODUCT');
		return $productIds ? array_map('intval', $productIds) : [];
	}

	public static function hasProducts(int $fuserId): bool
	{
		return (bool)WishlistTable::query()
			->where('FUSER_ID', $fuserId)
			->addSelect('ID')
			->setLimit(1)
			->exec()
			->fetch();
	}
}
