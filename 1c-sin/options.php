<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Rusgeocom\Rusgeocom\Catalog\Entities\Section;
use Rusgeocom\Rusgeocom\Catalog\Enums\CatalogViewType;
use Rusgeocom\Rusgeocom\Utils\Settings;
use Bitrix\Main\UserField\Internal\UserFieldHelper;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
	die();
}
global $USER;
global $APPLICATION;
if (!$USER->IsAdmin()) {
	return;
}

Loc::loadMessages(__FILE__);

$request = HttpApplication::getInstance()->getContext()->getRequest();
$moduleId = htmlspecialchars($request['mid'] != '' ? $request['mid'] : $request['id']);
Loader::includeModule($moduleId);

$userFieldManager = UserFieldHelper::getInstance()->getManager();
$arUserFields = $userFieldManager->GetUserFields("IBLOCK_2_SECTION",1, LANGUAGE_ID);
$listUserFields = [];
foreach ($arUserFields as $key => $value) {
	$listUserFields[$key] = $value['EDIT_FORM_LABEL'];
}
asort($listUserFields);

$listCatalogView = [];
foreach (CatalogViewType::cases() as $value) {
	$listCatalogView[$value->value] = $value->getName();
}

$tabs = [
	[
		'DIV' => 'edit1',
		'TAB' => 'Основные',
		'TITLE' => 'Основные',
		'OPTIONS' => [
			[
				Settings::NEW_YEAR_DESIGN_ENABLED_OPTION_CODE,
				'Новогоднее оформление',
				'N',
				['checkbox']
			],
			[
				Settings::EMAIL_CONFIRM_LINK_DAYS_LIFETIME,
				'Время жизни ссылки для подтверждения почты, дней (0 - бесконечно):',
				1,
				["text", 6],
			],
			[
				Settings::PRODUCT_IMAGES_LIMIT_IN_SLIDER_IN_SECTION,
				'Количество картинок у товара в разделе у слайдера (0 - будут показаны все):',
				4,
				["text", 6],
			],
			[
				Settings::PAGE_PRODUCTS_COUNT_IN_SECTION,
				'Количество товаров на странице раздела (по умолчанию - 32):',
				32,
				["text", 6],
			],
			[
				Settings::RUSGEOCOM_MANAGERS_ALLOWED_REMOTE_ADDRESSES,
				'Разрешенные ip-адреса для просмотра остатков:',
				'*',
				["textarea"],
			],
			[
				Settings::LOG_SECTION_USER_FIELDS,
				"Добавлять в лог изменения пользовательских полей раздела:",
				"",
				[
					"multiselectbox",
					$listUserFields
				]
			],
			[
				Settings::MAX_DAYS_REVIEW_RECOMMENDATION_ALIVE,
				'Количество дней для показа товара для отзыва (по умолчанию - 14):',
				14,
				["text", 6],
			],
			[
				Settings::MAX_QUANTITY_OF_VISIBLE_SUBSECTIONS,
				'Максимальное количество отображаемых подразделов в меню:',
				'6',
				["text", 6],
			],
			[
				Settings::MAX_DAYS_BEFORE_HIDE_DELIVERY_PERIOD,
				'Максимальное количество дней до скрытия периода доставки (0 для отключения ограничения):',
				'7',
				["text", 6],
			],
			[
				Settings::MAX_DELIVERY_PRICE_FOR_SHOW,
				'Максимальная цена до показа доставки по тарифам ТК:',
				'2000',
				["text", 6],
			],
			[
				Settings::SMS_AUTH_TIMEOUTS,
				'Ограничения времени отправки sms авторизации через запятую (например, 300,300,3600,86400):',
				'',
				["text", 6],
			],
			[
				Settings::MAX_DESCRIPTION_HEIGHTS_IN_SECTION,
				'Максимальная высота описания в разделе (desktop,tablet,mobile):',
				'250,250,250',
				["text", 6],
			],
			[
				Settings::EXPORT_REGISTRATIONS_TOKEN,
				'Токен для получения лога регистраций:',
				'',
				["text", 50],
			],
			[
				Settings::DEFAULT_CATALOG_VIEW,
				'Вид отображения списка товаров по умолчанию в разделе:',
				'',
				[
					"selectbox",
					$listCatalogView,
				],
			],
			[
				Settings::DELIVERY_POPUP_DESCRIPTION,
				'Текст для попапа доставок на странице товара:',
				'',
				["textarea"],
			],
			[
				Settings::DELIVERY_DEFAULT_HINT,
				'Текст подсказки для нерассчитанных доставок:',
				'',
				["textarea"],
			],
			[
				Settings::VERIFICATION_METROLOGY_OPT_PAPER_PRICE,
				'Цена листа бумаги для опта метрологии:',
				'300',
				["text", 6],
			],
			[
				Settings::VERIFICATION_RETAIL_PAPER_PRICE,
				'Цена листа бумаги:',
				'500',
				["text", 6],
			],
			[
				Settings::HOURS_BEFORE_BASKET_ABANDONED,
				'Срок срабатывания отчета брошеной корзины(часов):',
				'24',
				["text", 6],
			],
			[
				Settings::HOURS_FOR_ORDER_BEFORE_BASKET_ABANDONED,
				'Срок ограничения на отправку отчета брошеной корзины если клиент сделал заказ(часов):',
				'0',
				["text", 6],
			],
			[
				Settings::MAIN_PAGE_PREVIEW_USERS,
				'ID пользователей для отладки главной страницы (через запятную):',
				'',
				["text", 50],
			],
			[
				Settings::VAT_RATE,
				'Ставка НДС (%):',
				'20',
				["text", 6],
			],
			[
				Settings::IGNORED_USER_IDS_IN_REPORTS,
				'ID пользователей для исключения из отчета по статусам заказов (через запятную):',
				'',
				["text", 50],
			],
		]
	],
];

$tabControl = new CAdminTabControl('tabControl', $tabs);
$tabControl->begin();
?>
    <form action="<?= $APPLICATION->getCurPage(); ?>?mid=<?= $moduleId; ?>&lang=<?= LANGUAGE_ID; ?>" method="post">
		<?= bitrix_sessid_post(); ?>
		<?php
		foreach ($tabs as $tab) { // цикл по вкладкам
			if ($tab['OPTIONS']) {
				$tabControl->beginNextTab();
				__AdmSettingsDrawList($moduleId, $tab['OPTIONS']);
			}
		}
		$tabControl->buttons();
		?>
        <input type="submit" name="apply" value="Сохранить" class="adm-btn-save"/>
    </form>

<?php
$tabControl->end();

if ($request->isPost() && check_bitrix_sessid()) {

	foreach ($tabs as $tab) {
		foreach ($tab['OPTIONS'] as $option) {
			if (!is_array($option)) { // если это название секции
				continue;
			}
			if ($option['note']) { // если это примечание
				continue;
			}
			if ($request['apply']) { // сохраняем введенные настройки
				$optionValue = $request->getPost($option[0]);
				if(is_array($optionValue)){
					$optionValue = implode(",", $optionValue);
				}
				Option::set($moduleId, $option[0], $optionValue);
			}
		}
	}

	LocalRedirect($APPLICATION->getCurPage() . '?mid=' . $moduleId . '&lang=' . LANGUAGE_ID);
}