<?php
declare(strict_types=1);

namespace Rusgeocom\Rusgeocom\Catalog\Enums;

enum PriceSubscriptionStatus: string
{
	case New = 'new';
	case Cancelled = 'cancelled';
	case Disabled = 'disabled';
	case Sent = 'sent';
}
