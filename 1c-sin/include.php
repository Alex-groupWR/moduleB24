<? if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();
use Bitrix\Main\Application;

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($composerAutoload)) {
    require_once $composerAutoload;
}

$em =\Bitrix\Main\EventManager::getInstance();

//$em->addEventHandler('crm', 'OnAfterCrmCompanyAdd',
//    ['\Rusgeocom\Rusgeocom\Events\CrmCompany', 'onAfterCrmCompanyAdd']);
//
//$em->addEventHandler('crm', 'OnAfterCrmCompanyUpdate',
//    ['\Rusgeocom\Rusgeocom\Events\CrmCompany', 'onAfterCrmCompanyUpdate']);

//$em->addEventHandler('crm', 'Bitrix\Crm\EntityRequisite::OnAfterAdd',
//    ['\Rusgeocom\Rusgeocom\Events\CrmRequisite', 'onAfterAdd']);
//
//$em->addEventHandler('crm', 'onCrmRequisiteUpdate',
//    ['\Rusgeocom\Rusgeocom\Events\CrmRequisite', 'onAfterUpdate']);
//
//$em->addEventHandler('crm', 'OnBeforeCrmRequisiteUpdate',
//    ['\Rusgeocom\Rusgeocom\Events\CrmRequisite', 'onAfterUpdate']);

//$em->addEventHandler('crm', 'OnAfterCrmCompanyDelete',
//    ['\Rusgeocom\Rusgeocom\Events\CrmCompany', 'onAfterCrmCompanyDelete']);

\Bitrix\Main\Loader::registerAutoLoadClasses(
    'rusgeocom.rusgeocom',
    [
        '\\Rusgeocom\\Rusgeocom\\Exchange\\Controller\\Deal' => 'lib/Exchange/Controller/Deal.php',
    ]
);

$em->addEventHandler('main', 'OnEpilog', function () {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (preg_match('#/crm/(deal|company|contact|type/\d+)/#', $uri)) {
        \Rusgeocom\Rusgeocom\Utils\CrmOneCLinkAsset::inject();
    }
});


$em->addEventHandler('crm', 'OnBeforeCrmCompanyDelete',
    ['\Rusgeocom\Rusgeocom\Events\CrmCompany', 'onBeforeCrmCompanyDelete']);

$em->addEventHandler('crm', 'OnBeforeCrmDealDelete',
    ['\Rusgeocom\Rusgeocom\Events\CrmDeal', 'onBeforeCrmDealDelete']);

$em->addEventHandler('crm', 'Bitrix\Crm\EntityRequisite::OnBeforeDelete',
    ['\Rusgeocom\Rusgeocom\Events\CrmRequisite', 'onBeforeDelete']);

$em->addEventHandler('crm', 'OnBeforeCrmDealUpdate',
    ['\Rusgeocom\Rusgeocom\Events\CrmDeal', 'onBeforeCrmDealUpdate']);

$em->addEventHandler('crm', 'OnAfterCrmDealUpdate',
    ['\Rusgeocom\Rusgeocom\Events\CrmDeal', 'onAfterCrmDealUpdate']);

$em->addEventHandler(
    'main',
    'onPageStart',
    function () {
        $request = Application::getInstance()->getContext()->getRequest();

        // 1. Проверяем AJAX-запрос на удаление элементов CRM
        if ($request->get('action') === 'crm.controller.item.delete')
        {
            $entityTypeId = (int)$request->get('entityTypeId');
            $id = (int)$request->get('id');

            $allowedIds = array(1032, 1064, 1068, 1118, 1122, 1096, 1036);
            if (in_array($entityTypeId, $allowedIds))
            {
                $delete = \Rusgeocom\Rusgeocom\Events\CrmDynamicItem::onBeforeCrmDynamicItemDelete($entityTypeId, $id);

                if (!$delete)
                {
                    while (ob_get_level()) {
                        ob_end_clean();
                    }

                    header('Content-Type: application/json; charset=UTF-8');

                    $errorResponse = array(
                        'status' => 'error',
                        'data' => null,
                        'errors' => array(
                            array(
                                'message' => 'Этот элемент связан с 1С! Физическое удаление запрещено. Элемент помечен на удаление.',
                                'code' => '1C_ELEMENT_PROTECTED',
                                'customData' => null
                            )
                        )
                    );

                    echo json_encode($errorResponse, JSON_UNESCAPED_UNICODE);
                    die();
                }
            }
        }
    }
);