<?

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\Date;

class WishlistTable extends Entity\DataManager
{
	public static function getTableName()
	{
		return 'logema_wishlist';
	}

	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Entity\IntegerField('FUSER_ID', ['required' => true]),
			new Entity\StringField('PRODUCT', ['required' => true, 'size' => 30]),
			new Entity\DateField('DATE_INSERT', [
				'required' => true,
				'default_value' => function () {
					return new Date();
				}
			]),
		];
	}
}
