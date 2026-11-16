<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

/** A MONEI payment is bound, but the plugin cannot fetch it. The cause is already logged. */
final class UnreachableBoundPaymentException extends \RuntimeException
{
}
