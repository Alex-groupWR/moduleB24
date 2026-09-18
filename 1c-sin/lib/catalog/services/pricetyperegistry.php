<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Services;

use Bitrix\Catalog\GroupTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Rusgeocom\Rusgeocom\Catalog\Entities\PriceType;

class PriceTypeRegistry
{
	private static array $types = [];
	private static array $idsByName = [];

	/**
	 * @return PriceType[]
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getAll(): array
	{
		if (count(static::$types) === 0) {
			$iterator = GroupTable::query()
				->addSelect('ID')
				->addSelect('NAME')
				->setCacheTtl(60)
				->exec();

			while ($group = $iterator->fetch()) {
				static::$types[$group['ID']] = new PriceType((int)$group['ID'], $group['NAME']);
				static::$idsByName[$group['NAME']] = $group['ID'];
			}
		}

		return static::$types;
	}

	/**
	 * @param int $id
	 * @return PriceType|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getById(int $id): ?PriceType
	{
		return static::getAll()[$id] ?? null;
	}

	/**
	 * @param string $name
	 * @return PriceType|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public static function getByName(string $name): ?PriceType
	{
		$types = static::getAll();
		return $types[static::$idsByName[$name] ?? BASE_PRICE_ID] ?? null;
	}
}