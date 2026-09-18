<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Validate;

class OrderValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'markDelete', 'number1C', 'summa', 'dateDocument'];

    // Убрали 'vat' из списка строго обязательных полей
    private const REQUIRED_PRODUCT_KEYS = ['lineProductId1c', 'lineProductId'];

    /**
     * Валидация параметров заказа.
     * Массив $data передается по ссылке (&$data), чтобы изменения 'vat' сохранились.
     */
    public static function checkParams(array &$data): array
    {
        $error = parent::validate($data, self::REQUIRED_KEYS);
        if (!empty($error)) {
            return $error;
        }

        if (!empty($data['products'])) {
            if (!is_array($data['products'])) {
                return ['error' => 'VALIDATION_ERROR', 'message' => 'Поле products должно быть массивом'];
            }

            // Используем &$product, чтобы изменить значение прямо в массиве
            foreach ($data['products'] as $index => &$product) {
                // 1. Проверяем базовые обязательные поля продукта
                foreach (self::REQUIRED_PRODUCT_KEYS as $key) {
                    if (!array_key_exists($key, $product) || $product[$key] === null || $product[$key] === '') {
                        return [
                            'error'   => 'VALIDATION_ERROR',
                            'message' => "Товар #{$index}: отсутствует обязательное поле '{$key}'",
                        ];
                    }
                }

                if (
                    !array_key_exists('vat', $product)
                    || $product['vat'] === null
                    || $product['vat'] === 0
                    || $product['vat'] === '0'
                    || $product['vat'] === 'Без НДС'
                    || $product['vat'] === ''
                ) {
                    $product['vat'] = 'а';
                }

                // 3. Валидация формата строки 'vat' (например, '20%')
                if (!preg_match('/^\d{1,2}%$/', (string)$product['vat'])) {
                    return [
                        'error'   => 'VALIDATION_ERROR',
                        'message' => "Товар #{$index}: поле 'vat' должно быть строкой в формате цифр с процентом (например, '22%')",
                    ];
                }
            }
        }

        return [];
    }
}
