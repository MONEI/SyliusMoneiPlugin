<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Action;

use Monei\SyliusPlugin\Action\ConvertPaymentAction;
use Payum\Core\Request\Convert;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Core\Model\Address;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\Payment;

final class ConvertPaymentActionTest extends TestCase
{
    public function testItConvertsPaymentToArray(): void
    {
        $customer = new Customer();
        $customer->setEmail('test@example.com');
        $customer->setFirstName('Alex');
        $customer->setLastName('Test');

        $billingAddress = new Address();
        $billingAddress->setFirstName('Alex');
        $billingAddress->setLastName('Test');
        $billingAddress->setStreet('Passeig de Gràcia, 19');
        $billingAddress->setCity('Barcelona');
        $billingAddress->setPostcode('08007');
        $billingAddress->setCountryCode('ES');

        $order = new Order();
        $order->setNumber('000123');
        $order->setCustomer($customer);
        $order->setBillingAddress($billingAddress);

        $payment = new Payment();
        $payment->setAmount(1599);
        $payment->setCurrencyCode('EUR');
        $payment->setOrder($order);

        $action = new ConvertPaymentAction();

        $convert = new Convert($payment, 'array');
        $action->execute($convert);

        $result = $convert->getResult();

        $this->assertSame(1599, $result['amount']);
        $this->assertSame('EUR', $result['currency']);
        $this->assertSame('000123', $result['orderId']);
        $this->assertSame('test@example.com', $result['customer']['email']);
        $this->assertSame('ES', $result['billingDetails']['address']['country']);
    }

    public function testItSupportsPaymentConversion(): void
    {
        $action = new ConvertPaymentAction();

        $this->assertTrue($action->supports(new Convert(new Payment(), 'array')));
        $this->assertFalse($action->supports(new Convert(new Payment(), 'string')));
    }

    public function testItHandlesMissingCustomer(): void
    {
        $order = new Order();
        $order->setNumber('000456');

        $payment = new Payment();
        $payment->setAmount(500);
        $payment->setCurrencyCode('EUR');
        $payment->setOrder($order);

        $action = new ConvertPaymentAction();

        $convert = new Convert($payment, 'array');
        $action->execute($convert);

        $result = $convert->getResult();

        $this->assertSame(500, $result['amount']);
        $this->assertSame('000456', $result['orderId']);
        $this->assertArrayNotHasKey('customer', $result);
    }
}
