<?

namespace Rusgeocom\Rusgeocom\Catalog\Tables;

use Bitrix\Main\Entity;

class CatalogReceivedProductEmailNotificationTable extends Entity\DataManager
{
	public static function getTableName()
	{
		return 'logema_catalog_received_product_email_notification';
	}

	public static function getMap()
	{
		return [
			new Entity\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Entity\BooleanField('ACTIVE', ['required' => true, 'values' => ['N', 'Y']]),
			new Entity\IntegerField('PRODUCT_ID', ['required' => true]),
			new Entity\StringField('EMAIL', ['required' => true]),
			new Entity\StringField('DOMAIN', ['required' => true]),
			new Entity\StringField('NAME', ['required' => true]),
		];
	}
}
