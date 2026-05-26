<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\ResponseHandlers;

class PingResponseHandler implements ResponseHandlerInterface
{
	public function handle(array $request, array $response): array
	{
		$response['ok'] = true;

		return $response;
	}
}