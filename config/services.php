<?php

declare(strict_types=1);

use Monei\Internal\GuzzleHttp\Client;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Api\UserAgent;
use Monei\SyliusPlugin\Form\Type\MoneiGatewayConfigurationType;
use Monei\SyliusPlugin\Gateway\ForcePaymentRequestsListener;
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
};
