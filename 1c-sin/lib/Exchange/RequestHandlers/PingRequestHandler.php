<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestHandlers;

class PingRequestHandler implements RequestHandlerInterface
{
	public function handle(array $request): array
	{
		$request['ping'] = true;

		return $request;
	}
}