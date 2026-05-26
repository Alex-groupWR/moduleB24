<?php

declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Exchange\Transport;

interface TransportInterface
{
	public function send(array $data): array;
}