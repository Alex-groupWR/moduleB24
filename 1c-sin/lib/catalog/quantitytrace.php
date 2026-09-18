<?php
namespace Rusgeocom\Rusgeocom\Catalog;

use Bitrix\Catalog\ProductTable;
use Bitrix\Main\Loader;

/**
 * Класс для работы с количественным учётом
 */
class QuantityTrace
{
	/**
	 * Всем товаром ставит "количественный учёт" и "разрешить покупку при отсутствии" в дефолт.
	 * Возвращает количество обновлённых товаров
	 */
	public static function resetQuantityTraceForAllProducts() : int
	{
		Loader::includeModule('catalog');
		$counter = 0;

		// Количественный учёт
		$iterator = ProductTable::query()
			->addSelect('ID')
			->addFilter('!QUANTITY_TRACE', 'D')
			->exec();
		while ($row = $iterator->fetch()){
			static::resetQuantityTraceForProductById($row['ID']);
			$counter++;
		}

		// Разрешить покупку
		$iterator = ProductTable::query()
			->addSelect('ID')
			->addFilter('!CAN_BUY_ZERO', 'D')
			->exec();
		while ($row = $iterator->fetch()){
			static::resetQuantityTraceForProductById($row['ID']);
			$counter++;
		}

		return $counter;
	}

	public static function resetQuantityTraceForProductById(int $productId)
	{
		$fields = [
			'QUANTITY_TRACE' => 'D',
			'CAN_BUY_ZERO' => 'D',
		];
		ProductTable::update($productId, $fields);
	}

	protected static function checkAndReplaceQuantityTraceFromEvent(&$fields)
	{
		if ($fields['QUANTITY_TRACE'] != 'D'){
			$fields['QUANTITY_TRACE'] = 'D';
		}
		if ($fields['CAN_BUY_ZERO'] != 'D'){
			$fields['CAN_BUY_ZERO'] = 'D';
		}
	}

	/**
	 * Запрещает установку недефолтного значения
	 *
	 * @param $id
	 * @param $fields
	 */
	public static function onBeforeProductUpdate($id, &$fields)
	{
		static::checkAndReplaceQuantityTraceFromEvent($fields);
	}

	/**
	 * Запрещает установку недефолтного значения
	 *
	 * @param $fields
	 */
	public static function onBeforeProductAdd(&$fields)
	{
		static::checkAndReplaceQuantityTraceFromEvent($fields);
	}
}