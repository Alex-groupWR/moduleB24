<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Dto;

/**
 * Промежуточное представление товарной строки после парсинга данных из 1С.
 * Позволяет разделить парсинг (buildProductRowDto) и применение (applyRowData / createFromArray).
 */
final class ProductRowDto
{
    public function __construct(
        public readonly int   $lineProductId,
        public readonly array $fields,
    ) {}
}