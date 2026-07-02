<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Validate;

class OrderValidate extends BaseValidator
{
    private const REQUIRED_KEYS = ['guid', 'markDelete', 'number1C', 'summa', 'dateDocument'];

    private const REQUIRED_PRODUCT_KEYS = ['lineProductId1c', 'lineProductId', 'vat'];

    public static function checkParams(array $data): array
    {
        $error = parent::validate($data, self::REQUIRED_KEYS);
        if (!empty($error)) {
            return $error;
        }

        if (!empty($data['products'])) {
            if (!is_array($data['products'])) {
                return ['error' => 'VALIDATION_ERROR', 'message' => 'Поле products должно быть массивом'];
            }

            foreach ($data['products'] as $index => $product) {
                foreach (self::REQUIRED_PRODUCT_KEYS as $key) {
                    if (!array_key_exists($key, $product) || $product[$key] === null || $product[$key] === '') {
                        return [
                            'error'   => 'VALIDATION_ERROR',
                            'message' => "Товар #{$index}: отсутствует обязательное поле '{$key}'",
                        ];
                    }
                }

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
