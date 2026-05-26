<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestOneC;

class SameModifier implements RequestOneCInterface
{
	public function handle(array $request): array
	{
		return $request;
	}
}