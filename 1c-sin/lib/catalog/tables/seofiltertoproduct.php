<?

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity;

class SeoFilterToProductTable extends Entity\DataManager
{
	public static function getTableName()
	{
		return 'rusgeocom_seo_filter_to_product';
	}

	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Entity\IntegerField('FILTER_ID', ['required' => true]),
			new Entity\IntegerField('PRODUCT_ID', ['required' => true]),
			new Entity\ReferenceField('FILTER_ELEMENT',
				\Bitrix\Iblock\ElementTable::class,
				['=this.FILTER_ID' => 'ref.ID']
			),
		];
	}
}
