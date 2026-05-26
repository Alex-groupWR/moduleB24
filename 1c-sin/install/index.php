<?

use Bitrix\Main\Localization\Loc;

Class rusgeocom_rusgeocom extends CModule
{
    public function __construct()
    {
        $arModuleVersion = array();
        include(dirname(__FILE__) . "/version.php");
        $this->MODULE_ID = self::getModuleId();
        $this->MODULE_VERSION = $arModuleVersion["VERSION"];
        $this->MODULE_VERSION_DATE = $arModuleVersion["VERSION_DATE"];
        $this->MODULE_NAME = Loc::getMessage("rusgeocom_rusgeocom_MODULE_NAME");
        $this->MODULE_DESCRIPTION = Loc::getMessage("rusgeocom_rusgeocom_MODULE_DESC");

        $this->PARTNER_NAME = Loc::getMessage("LOGEMA_PARTNER_NAME");
        $this->PARTNER_URI = Loc::getMessage("LOGEMA_PARTNER_URI");
    }

    public static function getModuleId()
    {
        return basename(dirname(__DIR__));
    }

    public function DoInstall()
    {
        $this->InstallDB();
        $this->InstallFiles();
        $this->InstallEvents();
        RegisterModule(self::getModuleId());
    }

    public function InstallDB()
    {
        if (is_dir($d = dirname(__FILE__) . "/db/")) {
            global $DB;
            $DB->RunSQLBatch($d . strtolower($DB->type) . "/install.sql");
        }

        return true;
    }

    public function InstallFiles($arParams = array())
    {
        CopyDirFiles(__DIR__ . "/admin", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/admin");

        return true;
    }

    public function InstallEvents()
    {
        return true;
    }

    public function DoUninstall()
    {
        UnRegisterModule(self::getModuleId());
        $this->UnInstallEvents();
        $this->UnInstallFiles();
        $this->UnInstallDB();
    }

    public function UnInstallEvents()
    {
        return true;
    }

    public function UnInstallFiles()
    {
        DeleteDirFiles(__DIR__ . "/admin", $_SERVER["DOCUMENT_ROOT"] . "/bitrix/admin");

        return true;
    }

    public function UnInstallDB($arParams = array())
    {
        if (is_dir($d = dirname(__FILE__) . "/db/")) {
            global $DB;
            $DB->RunSQLBatch($d . strtolower($DB->type) . "/uninstall.sql");
        }

        return true;
    }
}
?>