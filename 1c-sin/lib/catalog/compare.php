<?php
namespace Rusgeocom\Rusgeocom\Catalog;

class Compare
{
	public static function addProduct(int $id)
	{
		$_SESSION['COMPARE_LIST'][$id] = $id;
	}

	/**
	 * @return int[]
	 */
	public static function getProductIds(): array
	{
		return $_SESSION['COMPARE_LIST'] ?: [];
	}

	public static function deleteProduct(int $id)
	{
		unset($_SESSION['COMPARE_LIST'][$id]);
	}

	public static function deleteByIds(array $productIds): void
	{
		foreach ($productIds as $productId) {
			static::deleteProduct($productId);
		}
	}

	public static function clear(): void
	{
		$_SESSION['COMPARE_LIST'] = [];
	}

	public static function hasProducts(): bool
	{
		return (bool)static::getProductIds();
	}
}