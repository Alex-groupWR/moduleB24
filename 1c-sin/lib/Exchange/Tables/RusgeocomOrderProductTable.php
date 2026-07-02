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
 * Промежуточная таблица товарных позиций заказов 1С → Битрикс24.
 *
 * Namespace: Rusgeocom\Rusgeocom\Exchange\Tables
 * Файл:      local/modules/rusgeocom.rusgeocom/lib/Exchange/Tables/RusgeocomOrderProductTable.php
 *
 * Таблица создаётся через Sprint\Migration — см. соответствующую миграцию.
 */
class RusgeocomOrderProductTable extends DataManager
{
    public static function getTableName(): string
    {
        return 'rusgeocom_order_product';
    }

    public static function getMap(): array
    {
        return [
            // ------------------------------------------------------------------
            // Первичный ключ
            // ------------------------------------------------------------------
            (new IntegerField('ID'))
                ->configurePrimary(true)
                ->configureAutocomplete(true)
                ->configureTitle('ID'),

            // ------------------------------------------------------------------
            // Привязка к сделке Битрикс24
            // ------------------------------------------------------------------
            (new IntegerField('DEAL_ID'))
                ->configureRequired(true)
                ->configureTitle('ID сделки Б24'),

            // ID строки товарной позиции в CRM (заполняется после сохранения продуктов)
            (new IntegerField('B24_LINE_PRODUCT_ID'))
                ->configureNullable(true)
                ->configureTitle('ID товарной строки Б24'),

            // ------------------------------------------------------------------
            // Идентификаторы со стороны 1С
            // ------------------------------------------------------------------
            (new StringField('GUID_1C'))
                ->configureNullable(false)
                ->configureDefaultValue('')
                ->addValidator(new LengthValidator(null, 100))
                ->configureTitle('GUID товара в 1С'),

            // Номер позиции в документе 1С (lineProductId1с из запроса)
            (new StringField('LINE_PRODUCT_ID_1C'))
                ->configureNullable(false)
                ->configureDefaultValue('')
                ->addValidator(new LengthValidator(null, 50))
                ->configureTitle('Номер строки в 1С'),

            // Номер позиции со стороны Б24 (lineProductId из запроса)
            (new StringField('LINE_PRODUCT_ID_B24'))
                ->configureNullable(false)
                ->configureDefaultValue('')
                ->addValidator(new LengthValidator(null, 50))
                ->configureTitle('Номер строки в Б24'),

            // ------------------------------------------------------------------
            // Данные позиции из 1С (все поля из products[])
            // ------------------------------------------------------------------
            (new FloatField('COUNT'))
                ->configureNullable(false)
                ->configureDefaultValue(0)
                ->configureTitle('Количество'),

            (new StringField('VID_PRICE'))
                ->configureNullable(false)
                ->configureDefaultValue('')
                ->addValidator(new LengthValidator(null, 100))
                ->configureTitle('Вид цены'),

            (new FloatField('PRICE'))
                ->configureNullable(false)
                ->configureDefaultValue(0)
                ->configureTitle('Цена'),

            (new FloatField('SUMMA'))
                ->configureNullable(false)
                ->configureDefaultValue(0)
                ->configureTitle('Сумма'),

            (new StringField('VAT'))
                ->configureNullable(false)
                ->configureDefaultValue('')
                ->addValidator(new LengthValidator(null, 20))
                ->configureTitle('Ставка НДС'),

            (new FloatField('SUMMA_NDS'))
                ->configureNullable(false)
                ->configureDefaultValue(0)
                ->configureTitle('Сумма НДС'),

            (new FloatField('SKIDKA_RUCH'))
                ->configureNullable(false)
                ->configureDefaultValue(0)
                ->configureTitle('Скидка ручная'),

            (new FloatField('SKIDKA_AUTO'))
                ->configureNullable(false)
                ->configureDefaultValue(0)
                ->configureTitle('Скидка авто'),

            // ------------------------------------------------------------------
            // Служебные поля
            // ------------------------------------------------------------------
            (new DatetimeField('CREATED_AT'))
                ->configureRequired(true)
                ->configureTitle('Дата создания'),

            (new DatetimeField('UPDATED_AT'))
                ->configureRequired(true)
                ->configureTitle('Дата обновления'),
        ];
    }

    // ------------------------------------------------------------------
    // Публичное API
    // ------------------------------------------------------------------

    /**
     * Upsert товарной позиции.
     *
     * Ищет запись по паре (DEAL_ID + LINE_PRODUCT_ID_1C).
     * Если найдена — обновляет, иначе — вставляет.
     *
     * @param int   $dealId    ID сделки Б24
     * @param int   $b24LineId ID строки товарной позиции в CRM (0 если ещё неизвестен)
     * @param array $product   Элемент массива products[] из запроса 1С
     */
    public static function upsert(int $dealId, int $b24LineId, array $product): void
    {
        $lineId1c = (string)($product['lineProductId1с'] ?? '');
        $now      = new DateTime();

        $existing = static::getRow([
            'filter' => [
                '=DEAL_ID'            => $dealId,
                '=LINE_PRODUCT_ID_1C' => $lineId1c,
            ],
            'select' => ['ID'],
        ]);

        $fields = [
            'DEAL_ID'             => $dealId,
            'B24_LINE_PRODUCT_ID' => $b24LineId > 0 ? $b24LineId : null,
            'GUID_1C'             => (string)($product['guid']        ?? ''),
            'LINE_PRODUCT_ID_1C'  => $lineId1c,
            'LINE_PRODUCT_ID_B24' => (string)($product['lineProductId'] ?? '0'),
            'COUNT'               => (float)($product['count']         ?? 0),
            'VID_PRICE'           => (string)($product['vid_price']    ?? ''),
            'PRICE'               => (float)($product['price']         ?? 0),
            'SUMMA'               => (float)($product['summa']         ?? 0),
            'VAT'                 => (string)($product['vat']          ?? ''),
            'SUMMA_NDS'           => (float)($product['summa_nds']     ?? 0),
            'SKIDKA_RUCH'         => (float)($product['skidka_ruch']   ?? 0),
            'SKIDKA_AUTO'         => (float)($product['skidka_auto']   ?? 0),
            'UPDATED_AT'          => $now,
        ];

        if ($existing) {
            static::update($existing['ID'], $fields);
        } else {
            $fields['CREATED_AT'] = $now;
            static::add($fields);
        }
    }
}