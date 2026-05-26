<?php

namespace Rusgeocom\Rusgeocom\Exchange\Enum;

enum SegmentEnum: int
{
    case RETAIL          = 5104;
    case WHOLESALE_GEON  = 5105;
    case TENDER_GEON     = 5106;
    case MARKETPLACE     = 5107;
    case RETAIL_CHAIN    = 5108;
    case WHOLESALER      = 5109;
    case OPT             = 5110;
    case DEALER          = 5111;

    public function label(): string
    {
        return match($this) {
            self::RETAIL         => 'Розница',
            self::WHOLESALE_GEON => 'Оптовики (ГЕОН)',
            self::TENDER_GEON    => 'Тендер (ГЕОН)',
            self::MARKETPLACE    => 'Маркетплейс',
            self::RETAIL_CHAIN   => 'Ретейл',
            self::WHOLESALER     => 'Оптовик',
            self::OPT            => 'Опт',
            self::DEALER         => 'Дилер',
        };
    }

    public static function getIdByText(string $text): ?int
    {
        $text = trim($text);
        foreach (self::cases() as $case) {
            if ($case->label() === $text) {
                return $case->value;
            }
        }
        return null;
    }

    public static function getTextById(int $id): ?string
    {
        return self::tryFrom($id)?->label();
    }
}