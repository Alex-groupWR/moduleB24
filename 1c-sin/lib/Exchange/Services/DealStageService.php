<?php
// 1c-sin/lib/Exchange/Services/DealStageService.php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Services;

use Bitrix\Crm\DealTable;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Loader;
use CCrmOwnerType;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealDirectionEnum;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealStages\StageOneCTestSredaEnum;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealStages\StageRetailEnum;
use Rusgeocom\Rusgeocom\Exchange\Traits\ExchangeHelperTrait;

class DealStageService
{
    use ExchangeHelperTrait;

    private static bool $isSyncFromOneC = false;

    public static function setSyncFromOneC(bool $value): void
    {
        self::$isSyncFromOneC = $value;
    }

    public static function isSyncFromOneC(): bool
    {
        return self::$isSyncFromOneC;
    }

    public static function findDealId(array $request): ?int
    {
        Loader::includeModule('crm');

        if (!empty($request['b24_id'])) {
            $row = DealTable::getList(['filter' => ['=ID' => (int)$request['b24_id']], 'select' => ['ID']])->fetch();
            if ($row) {
                return (int)$row['ID'];
            }
        }

        if (!empty($request['guid'])) {
            $row = DealTable::getList(['filter' => ['=ORIGIN_ID' => $request['guid']], 'select' => ['ID']])->fetch();
            if ($row) {
                return (int)$row['ID'];
            }
        }

        return null;
    }

    public static function updateStage(
        int $dealId,
        DealDirectionEnum $direction,
        StageRetailEnum|StageOneCTestSredaEnum $stage,
        string $guid
    ): array {
        Loader::includeModule('crm');

        $factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
        if (!$factory) {
            return self::errorResult('SYSTEM_ERROR', 'Фабрика сделок не найдена', $guid);
        }

        $item = $factory->getItem($dealId);
        if (!$item) {
            return self::errorResult('NOT_FOUND', "Сделка {$dealId} не найдена", $guid);
        }

        $actualGuid = (string)($item->get(Item::FIELD_NAME_ORIGIN_ID) ?: $guid);

        foreach ([
                     'CATEGORY_ID' => $direction->getB24EnumId(),
                     'STAGE_ID' => $stage->value,
                 ] as $field => $value) {
            if ($item->hasField($field)) {
                $item->set($field, $value);
            }
        }

        self::setSyncFromOneC(true);
        try {
            $result = $factory->getUpdateOperation($item)
                ->disableCheckAccess()
                ->launch();
        } finally {
            self::setSyncFromOneC(false);
        }

        if (!$result->isSuccess()) {
            return self::errorResult('UPDATE_ERROR', implode(', ', $result->getErrorMessages()), $actualGuid);
        }

        return self::successResult($dealId, $actualGuid, 'updated');
    }

    public static function getStageSnapshot(int $dealId): array
    {
        Loader::includeModule('crm');

        return DealTable::getList([
            'filter' => ['=ID' => $dealId],
            'select' => ['STAGE_ID', 'CATEGORY_ID', 'ORIGIN_ID'],
        ])->fetch() ?: [];
    }
}