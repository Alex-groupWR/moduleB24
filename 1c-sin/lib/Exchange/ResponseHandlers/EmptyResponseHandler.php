<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\ResponseHandlers;

class EmptyResponseHandler implements ResponseHandlerInterface
{
	public function handle(array $request, array $response): array
	{
		return [];
	}
}