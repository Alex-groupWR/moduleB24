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

    public static function fromB24EnumId(int $id): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->getB24EnumId() === $id) {
                return $case;
            }
        }
        return null;
    }

    public function getLabelFromStageValue(string $stageValue): ?string
    {
        if ($stageValue === '') {
            return null;
        }

        return match ($this) {
            self::RETAIL => match ($stageValue) {
                StageRetailEnum::NEW->value => 'Подготовка КП',
                StageRetailEnum::PREPARATION->value => 'Согласование КП/Договора',
                StageRetailEnum::PREPAYMENT_INVOICE->value => 'Предоплата',
                StageRetailEnum::EXECUTING->value => 'Отгрузка \ Поверка',
                StageRetailEnum::FINAL_INVOICE->value => 'Дебиторская задолженность',
                StageRetailEnum::WON->value => 'Сделка успешна',
                StageRetailEnum::LOSE->value => 'Сделка провалена',
                default => null,
            },
            self::ONEC_TEST_SREDA => match ($stageValue) {
                StageOneCTestSredaEnum::NEW->value => 'Подготовка КП',
                StageOneCTestSredaEnum::PREPARATION->value => 'Согласование КП/Договора',
                StageOneCTestSredaEnum::PREPAYMENT_INVOICE->value => 'Предоплата',
                StageOneCTestSredaEnum::EXECUTING->value => 'Отгрузка \ Поверка',
                StageOneCTestSredaEnum::FINAL_INVOICE->value => 'Дебиторская задолженность',
                StageOneCTestSredaEnum::WON->value => 'Сделка успешна',
                StageOneCTestSredaEnum::LOSE->value => 'Сделка провалена',
                StageOneCTestSredaEnum::APOLOGY->value => 'Анализ причины провала',
                default => null,
            },
        };
    }
}
