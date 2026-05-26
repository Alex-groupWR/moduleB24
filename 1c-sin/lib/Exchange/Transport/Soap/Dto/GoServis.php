<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Transport\Soap\Dto;

class GoServis
{
	/**
	 * @var string
	 */
	public string $Data;
}

/*
Пример рабочего запроса

<x:Envelope
    xmlns:x="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:pcc="PcConector">
    <x:Header/>
    <x:Body>
        <pcc:GoServis>
            <pcc:Data>eyJGdW5jIjoiZ2V0Z3VpZCIsIkxvZ2luIjoiU2l0ZTEiLCJQYXNzIjoiNjkgMDEgODMgNTAgMTAgREUgOTEgQjggM0EgMEMgRTcgM0MgNkIgM0EgQzIgQzIifQ==</pcc:Data>
        </pcc:GoServis>
    </x:Body>
</x:Envelope>
*/