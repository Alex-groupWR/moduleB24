<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\RequestOneC;

interface RequestOneCInterface
{
	public function handle(array $request): array;
}