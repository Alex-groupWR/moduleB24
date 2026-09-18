<?php

namespace Rusgeocom\Rusgeocom\Utils;

use Bitrix\Main\Page\Asset;

class CrmOneCLinkAsset
{
    public static function inject(): void
    {
        static $injected = false;
        if ($injected) {
            return;
        }
        $injected = true;

        Asset::getInstance()->addString(self::getScript());
    }

    private static function getScript(): string
    {
        return <<<HTML
<script>
BX.ready(function () {
    if (document.__rusgeocomOneCLinkInited) {
        return;
    }
    document.__rusgeocomOneCLinkInited = true;

    document.addEventListener('click', function (event) {
        var link = event.target.closest('a.rusgeocom-1c-link');
        if (!link) {
            return;
        }

        event.preventDefault();

        BX.SidePanel.Instance.open(link.href, {
            width: 1200,
            allowChangeHistory: false,
            cacheable: false,
            title: 'Документ в 1С'
        });
    });
});
</script>
HTML;
    }
}