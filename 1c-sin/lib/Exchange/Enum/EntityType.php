<?php

namespace Rusgeocom\Rusgeocom\Exchange\Enum;

enum EntityType: string
{
    case COMPANY = 'company';
    case CONTACT = 'contact';
    case USER = 'user';
    case SMART_PROCESS_BUSINESS_REGION = '1032';
    case SMART_PROCESS_AGREEMENT_INDIV = '1064';
    case SMART_PROCESS_AGREEMENT_TYPE = '1068';
    case SMART_PROCESS_ORGANISATION = '1118';
    case SMART_PROCESS_METHOD_DELIVERY = '1122';
    case SMART_PROCESS_WAREHOUSE = '1096';

    public function getFactoryId(): ?int
    {
        return (int)$this->value;
    }
}