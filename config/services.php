<?php

declare(strict_types=1);

use Monei\Internal\GuzzleHttp\Client;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Api\UserAgent;
use Monei\SyliusPlugin\Callback\MoneiNotifyPaymentProvider;
use Monei\SyliusPlugin\CommandHandler\CapturePaymentRequestHandler;
use Monei\SyliusPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Monei\SyliusPlugin\CommandHandler\StatusPaymentRequestHandler;
use Monei\SyliusPlugin\CommandProvider\MoneiCommandProvider;
use Monei\SyliusPlugin\EventListener\MoneiExceptionListener;
use Monei\SyliusPlugin\Form\Type\MoneiGatewayConfigurationType;
use Monei\SyliusPlugin\Gateway\ForcePaymentRequestsListener;
use Monei\SyliusPlugin\OrderPay\MoneiHttpResponseProvider;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\CreatePaymentRequestFactory;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Payment\SyncContext;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('monei.http_client', Client::class)
        ->args([['timeout' => 30, 'connect_timeout' => 10]]);

    $services->set(MoneiApiFactory::class)
        ->args([service('monei.http_client'), UserAgent::current()]);

    $services->set(MoneiGatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => 'monei', 'label' => 'monei.gateway_factory.monei'])
        ->tag('form.type');

    $services->set(ForcePaymentRequestsListener::class)
        ->tag('doctrine.event_listener', ['event' => 'prePersist']);

    $services->set(SyncContext::class);

    $services->set(PaymentLock::class)
        ->args([service('doctrine.orm.entity_manager')]);

    $services->set(StatusApplier::class)
        ->args([
            service('sylius_abstraction.state_machine'),
            service(SyncContext::class),
            service('monolog.logger.monei'),
        ]);

    $services->set(BoundPaymentAccess::class)
        ->args([
            service('sylius.repository.payment_method'),
            service(MoneiApiFactory::class),
            service('monolog.logger.monei'),
        ]);

    $services->set(CreatePaymentRequestFactory::class)
        ->args([
            service('request_stack'),
            service('clock'),
            '%sylius_order.order_expiration_period%',
        ]);

    $services->set(MoneiCommandProvider::class)
        ->tag('sylius.payment_request.command_provider', ['gateway_factory' => 'monei']);

    $services->set(MoneiHttpResponseProvider::class)
        ->args([
            service('twig'),
            service('sylius_shop.provider.order_pay.after_pay_url'),
        ])
        ->tag('sylius.payment_request.provider.http_response', ['gateway_factory' => 'monei']);

    $services->set(CapturePaymentRequestHandler::class)
        ->args([
            service('sylius.repository.payment_request'),
            service(PaymentLock::class),
            service(BoundPaymentAccess::class),
            service(MoneiApiFactory::class),
            service(CreatePaymentRequestFactory::class),
            service(StatusApplier::class),
            service('sylius_abstraction.state_machine'),
            service('sylius_shop.provider.order_pay.after_pay_url'),
            service('router'),
            service('request_stack'),
            service('monolog.logger.monei'),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus']);

    $services->set(StatusPaymentRequestHandler::class)
        ->args([
            service('sylius.repository.payment_request'),
            service(PaymentLock::class),
            service(BoundPaymentAccess::class),
            service(StatusApplier::class),
            service('sylius_abstraction.state_machine'),
            service('monolog.logger.monei'),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus']);

    $services->set(MoneiNotifyPaymentProvider::class)
        ->args([service('sylius.repository.payment')])
        ->tag('sylius.payment_request.payment_notify_provider');

    $services->set(NotifyPaymentRequestHandler::class)
        ->args([
            service('sylius.repository.payment_request'),
            service(PaymentLock::class),
            service(MoneiApiFactory::class),
            service(StatusApplier::class),
            service('sylius_abstraction.state_machine'),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus']);

    $services->set(MoneiExceptionListener::class)
        ->args([service('monolog.logger.monei')])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception']);
};
