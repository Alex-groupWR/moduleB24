<?

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity;
use Bitrix\Main\Type\DateTime;

class ViewedProductsTable extends Entity\DataManager
{
	public static function getTableName()
	{
		return 'rusgeocom_viewed_products';
	}

	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Entity\IntegerField('FUSER_ID', ['required' => true]),
			new Entity\IntegerField('PRODUCT_ID', ['required' => true, 'size' => 30]),
			new Entity\DateTimeField('DATE_VIEW', [
				'required' => true,
				'default_value' => function() {
					return new DateTime();
				},
			]),
		];
	}
}
