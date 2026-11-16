<?php

declare(strict_types=1);

use Monei\Internal\GuzzleHttp\Client;
use Monei\Internal\GuzzleHttp\HandlerStack;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Tests\Functional\MoneiLogHandler;
use Monei\SyliusPlugin\Tests\Functional\MoneiMockHandler;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    if ('test' !== $container->env()) {
        return;
    }

    $services = $container->services();

    $services->set('monei.test.mock_handler', MoneiMockHandler::class);

    $services->set('monei.test.handler_stack', HandlerStack::class)
        ->factory([HandlerStack::class, 'create'])
        ->args([service('monei.test.mock_handler')]);

    $services->set('monei.http_client', Client::class)
        ->args([['handler' => service('monei.test.handler_stack')]]);

    $services->set('monei.test.log_handler', MoneiLogHandler::class);

    // Tests fetch these before any plugin service uses them, so the container would remove them.
    $services->alias('monei.test.payment_lock', PaymentLock::class)->public();
    $services->alias('monei.test.status_applier', StatusApplier::class)->public();
};
