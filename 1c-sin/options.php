<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\HttpApplication;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UserTable;
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


Loader::includeModule('intranet');

$usersRes = UserTable::getList([
    'select' => ['ID', 'NAME', 'LAST_NAME', 'LOGIN'],
    'filter' => ['ACTIVE' => 'Y', '!UF_DEPARTMENT' => false],
    'order'  => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC'],
]);

$listManagers = [];
while ($user = $usersRes->fetch()) {
    if (Loader::includeModule('intranet') && class_exists('CIntranetUtils')) {
        if (!\CIntranetUtils::IsIntranetUser($user['ID'])) {
            continue; // экстранет / внешний пользователь — пропускаем
        }
    }
    $fio = trim($user['LAST_NAME'] . ' ' . $user['NAME']);
    $listManagers[$user['ID']] = ($fio !== '' ? $fio : $user['LOGIN']) . ' (ID ' . $user['ID'] . ')';
}
asort($listManagers);

$tabs = [
    [
        'DIV' => 'edit1',
        'TAB' => 'Основные',
        'TITLE' => 'Основные',
        'OPTIONS' => [
            [
                Settings::DEAL_SEND_TO_ONEC_ALLOWED_USERS,
                'Пользователи, которым доступна ручная отправка сделки в 1С (кнопка на карточке):',
                '',
                [
                    "multiselectbox",
                    $listManagers
                ]
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