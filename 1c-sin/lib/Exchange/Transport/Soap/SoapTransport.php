<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Transport\Soap;

use Rusgeocom\Rusgeocom\Exchange\ExchangeProtocol;
use Rusgeocom\Rusgeocom\Exchange\Transport\Soap\Dto\GoServis;
use Rusgeocom\Rusgeocom\Exchange\Transport\TransportInterface;
use SoapClient;

class SoapTransport implements TransportInterface
{
	private SoapClient $client;

	public function __construct(SoapClient $soapClient)
	{
		$this->client = $soapClient;
	}

	public function send(array $data): array
	{
		$params = new GoServis();
		$params->Data = ExchangeProtocol::serialize($data);
		$response = $this->client->__soapCall('GoServis', [$params]);
		return ExchangeProtocol::deserialize($response->return);
	}
}

