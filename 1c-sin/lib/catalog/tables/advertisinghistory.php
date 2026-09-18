<?

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\Date;

class AdvertisingHistoryTable extends Entity\DataManager
{
	public static function getTableName()
	{
		return 'rusgeocom_advertising_history';
	}

	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Entity\IntegerField('BLOCK_ID', ['required' => true]),
			new Entity\IntegerField('STATUS_ID', ['required' => true]),
			new Entity\DateField('DATE', [
				'required' => true,
				'default_value' => function () {
					return new Date();
				}
			]),
		];
	}
}
