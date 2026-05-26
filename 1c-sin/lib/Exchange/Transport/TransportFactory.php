<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Transport;

use Rusgeocom\Rusgeocom\Exchange\ExchangeConfig;
use Rusgeocom\Rusgeocom\Exchange\Transport\Soap\SoapTransport;
use SoapClient;

class TransportFactory
{
	public static function createSoap(): SoapTransport
	{
		$client = new SoapClient(ExchangeConfig::getSoapUrl(), [
		]);

		return new SoapTransport($client);
	}
}