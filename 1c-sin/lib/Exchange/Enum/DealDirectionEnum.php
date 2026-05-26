<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Enum;

use Rusgeocom\Rusgeocom\Exchange\Enum\DealStages\StageOneCTestSredaEnum;
use Rusgeocom\Rusgeocom\Exchange\Enum\DealStages\StageRetailEnum;

enum DealDirectionEnum: string
{
    case RETAIL = 'Розница';
    case ONEC_TEST_SREDA = '1С ТЕСТ среда';


    public function getB24EnumId(): int
    {
        return match ($this) {
            self::RETAIL => 0,
            self::ONEC_TEST_SREDA => 13,
        };
    }


    public function getStageFromLabel(string $label): StageOneCTestSredaEnum|StageRetailEnum
    {
        return match ($this) {
            self::RETAIL => match ($label) {
                'Подготовка КП' => StageRetailEnum::NEW,
                'Согласование КП/Договора' => StageRetailEnum::PREPARATION,
                'Предоплата' => StageRetailEnum::PREPAYMENT_INVOICE,
                'Отгрузка \ Поверка' => StageRetailEnum::EXECUTING,
                'Дебиторская задолженность' => StageRetailEnum::FINAL_INVOICE,
                'Сделка успешна' => StageRetailEnum::WON,
                'Сделка провалена' => StageRetailEnum::LOSE,
                default => null,
            },
            self::ONEC_TEST_SREDA => match ($label) {
                'Подготовка КП' => StageOneCTestSredaEnum::NEW,
                'Согласование КП/Договора'   => StageOneCTestSredaEnum::PREPARATION,
                'Предоплата'  => StageOneCTestSredaEnum::PREPAYMENT_INVOICE,
                'Отгрузка \ Поверка'  => StageOneCTestSredaEnum::EXECUTING,
                'Дебиторская задолженность'  => StageOneCTestSredaEnum::FINAL_INVOICE,
                'Сделка успешна'  => StageOneCTestSredaEnum::WON,
                'Сделка провалена'  => StageOneCTestSredaEnum::LOSE,
                'Анализ причины провала'  => StageOneCTestSredaEnum::APOLOGY,
                default => null,
            },
        };
    }
}
