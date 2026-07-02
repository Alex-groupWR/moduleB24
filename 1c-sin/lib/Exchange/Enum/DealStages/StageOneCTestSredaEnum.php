<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Enum\DealStages;

enum StageOneCTestSredaEnum: string
{
    case NEW = 'C13:NEW';                    // Подготовка КП
    case PREPARATION = 'C13:PREPARATION';    // Согласование КП/Договора
    case PREPAYMENT_INVOICE = 'C13:PREPAYMENT_INVOIC'; // Предоплата
    case EXECUTING = 'C13:EXECUTING';        // Отгрузка \ Поверка
    case FINAL_INVOICE = 'C13:FINAL_INVOICE'; // Дебиторская задолженность
    case WON = 'C13:WON';                    // Сделка успешна
    case LOSE = 'C13:LOSE';                  // Сделка провалена
    case APOLOGY = 'C13:APOLOGY';            // Анализ причины провала

}