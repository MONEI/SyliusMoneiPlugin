<?php

declare(strict_types=1);

use Monei\Internal\GuzzleHttp\Client;
use Monei\SyliusPlugin\Form\Type\MoneiGatewayConfigurationType;
use Monei\SyliusPlugin\Gateway\ForcePaymentRequestsListener;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set('monei.http_client', Client::class)
        ->args([['timeout' => 30, 'connect_timeout' => 10]]);

    $services->set(MoneiGatewayConfigurationType::class)
        ->tag('sylius.gateway_configuration_type', ['type' => 'monei', 'label' => 'monei.gateway_factory.monei'])
        ->tag('form.type');

    $services->set(ForcePaymentRequestsListener::class)
        ->tag('doctrine.event_listener', ['event' => 'prePersist']);
};
