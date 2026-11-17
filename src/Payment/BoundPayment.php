<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Payment;

use Monei\Model\Payment as MoneiPayment;
use Monei\SyliusPlugin\Api\MoneiApi;

/** A MONEI payment bound to a Sylius payment, with the API of the account that holds it. */
final readonly class BoundPayment
{
    public function __construct(
        public MoneiPayment $payment,
        public MoneiApi $api,
    ) {
    }
}
