<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Tables;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\Type\DateTime;

/**
 * Локальный снэпшот остатков товаров по складам, зеркалирующий 1С (get_store_amount).
 * Ключ уникальности: (PRODUCT_GUID, STORE_GUID) — см. миграцию с UNIQUE INDEX.
 *
 * Файл: local/modules/rusgeocom.rusgeocom/lib/Exchange/Tables/StoreAmountTable.php
 */
class StoreAmountTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'rusgeocom_store_amount';
    }

    public static function getMap(): array
    {
        return [
            (new IntegerField('ID'))
                ->configurePrimary(true)
                ->configureAutocomplete(true),

            (new StringField('PRODUCT_GUID'))
                ->configureRequired(true)
                ->addValidator(new LengthValidator(null, 50))
                ->configureTitle('GUID товара в 1С'),

            // Кэш резолвинга — чтобы не ходить в ElementTable на каждое чтение.
            // Может протухать при пересоздании товара с тем же GUID (крайне редкий кейс, PRODUCT_ID стабилен).
            (new IntegerField('PRODUCT_B24_ID'))
                ->configureNullable(true)
                ->configureTitle('ID товара в Б24'),

            (new StringField('STORE_GUID'))
                ->configureRequired(true)
                ->addValidator(new LengthValidator(null, 50))
                ->configureTitle('GUID склада в 1С'),

            (new StringField('STORE_B24_ID'))
                ->configureNullable(true)
                ->configureTitle('GUID склада в Б24'),

            (new FloatField('AMOUNT'))
                ->configureRequired(true)
                ->configureDefaultValue(0)
                ->configureTitle('Доступно'),

            (new FloatField('RESERVE_AMOUNT'))
                ->configureRequired(true)
                ->configureDefaultValue(0)
                ->configureTitle('В резерве'),

            (new StringField('LAST_RESULT'))
                ->configureNullable(false)
                ->configureDefaultValue('ок')
                ->addValidator(new LengthValidator(null, 50))
                ->configureTitle('Результат последнего ответа 1С'),

            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(true)
                ->configureDefaultValue(new DateTime())
                ->configureTitle('Дата обновления'),
        ];
    }

    /**
     * Upsert одной записи (product_guid, store_guid)
     */
    public static function upsertOne(string $productGuid, ?int $productB24Id, string $storeGuid, ?int $storeB24Id, float $amount, float $reserveAmount, string $result = 'ок'): void
    {
        $existing = static::getRow([
            'filter' => ['=PRODUCT_GUID' => $productGuid, '=STORE_GUID' => $storeGuid],
            'select' => ['ID'],
        ]);

        $fields = [
            'PRODUCT_GUID' => $productGuid,
            'PRODUCT_B24_ID' => $productB24Id,
            'STORE_GUID' => $storeGuid,
            'STORE_B24_ID' => $storeB24Id,
            'AMOUNT' => $amount,
            'RESERVE_AMOUNT' => $reserveAmount,
            'LAST_RESULT' => $result,
            'UPDATED_AT' => new DateTime(),
        ];

        if ($existing) {
            static::update($existing['ID'], $fields);
        } else {
            static::add($fields);
        }
    }

    /**
     * Быстрая выборка остатков для набора товаров на конкретном складе
     *
     * @param string[] $productGuids
     * @return array<string, array{amount:float, reserveAmount:float, result:string, updatedAt:string}> ключ — PRODUCT_GUID
     */
    public static function getForProductsOnStore(array $productGuids, string $storeGuid): array
    {
        if (empty($productGuids)) {
            return [];
        }

        $rows = static::getList([
            'filter' => ['@PRODUCT_GUID' => $productGuids, '=STORE_GUID' => $storeGuid],
            'select' => ['PRODUCT_GUID', 'AMOUNT', 'RESERVE_AMOUNT', 'LAST_RESULT', 'UPDATED_AT'],
        ])->fetchAll();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['PRODUCT_GUID']] = [
                'amount' => (float)$row['AMOUNT'],
                'reserveAmount' => (float)$row['RESERVE_AMOUNT'],
                'result' => (string)$row['LAST_RESULT'],
                'updatedAt' => $row['UPDATED_AT'] instanceof DateTime ? $row['UPDATED_AT']->format('Y-m-d H:i:s') : '',
            ];
        }

        return $result;
    }
}