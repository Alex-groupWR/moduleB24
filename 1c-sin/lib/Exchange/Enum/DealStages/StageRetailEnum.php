<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Enum\DealStages;

enum StageRetailEnum: string
{
    case NEW = 'NEW';                       // Подготовка КП
    case PREPARATION = 'PREPARATION';       // Согласование КП/Договора
    case PREPAYMENT_INVOICE = 'EXECUTING';  // Предоплата
    case EXECUTING = 'UC_Z5GSDG';           // Отгрузка \ Поверка
    case FINAL_INVOICE = 'UC_Z12KVP';       // Дебиторская задолженность
    case WON = 'WON';                       // Сделка успешна
    case LOSE = 'LOSE';                     // Сделка провалена

}