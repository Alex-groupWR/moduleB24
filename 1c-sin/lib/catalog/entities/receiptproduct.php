<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Entities;

interface ReceiptProduct
{
	public function getName(): string;
	public function needVatInReceipt(): bool;
}