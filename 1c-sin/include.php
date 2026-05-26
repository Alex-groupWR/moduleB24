<? if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

$em =\Bitrix\Main\EventManager::getInstance();

$em->addEventHandler('crm', 'OnAfterCrmCompanyAdd',
    ['\Rusgeocom\Rusgeocom\Events\CrmCompany', 'onAfterCrmCompanyAdd']);

$em->addEventHandler('crm', 'OnAfterCrmCompanyUpdate',
    ['\Rusgeocom\Rusgeocom\Events\CrmCompany', 'onAfterCrmCompanyUpdate']);

$em->addEventHandler('crm', 'Bitrix\Crm\EntityRequisite::OnAfterAdd',
    ['\Rusgeocom\Rusgeocom\Events\CrmRequisite', 'onAfterAdd']);

$em->addEventHandler('crm', 'onCrmRequisiteUpdate',
    ['\Rusgeocom\Rusgeocom\Events\CrmRequisite', 'onAfterUpdate']);

$em->addEventHandler('crm', 'OnBeforeCrmRequisiteUpdate',
    ['\Rusgeocom\Rusgeocom\Events\CrmRequisite', 'onAfterUpdate']);

//$em->addEventHandler('crm', 'OnAfterCrmCompanyDelete',
//    ['\Rusgeocom\Rusgeocom\Events\CrmCompany', 'onAfterCrmCompanyDelete']);

