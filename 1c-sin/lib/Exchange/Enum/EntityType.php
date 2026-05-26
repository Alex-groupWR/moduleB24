<?php

namespace Rusgeocom\Rusgeocom\Exchange\Enum;

enum EntityType: string
{
    case COMPANY = 'company';
    case CONTACT = 'contact';
    case USER = 'user';
    case SMART_PROCESS_1032 = '1032';
    case SMART_PROCESS_1064 = '1064';
    case SMART_PROCESS_1068 = '1068';
    case SMART_PROCESS_1118 = '1118';
    case SMART_PROCESS_1122 = '1122';

    public function getFactoryId(): ?int
    {
        return (int)$this->value;
    }
}