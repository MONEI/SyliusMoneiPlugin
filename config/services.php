<?php

declare(strict_types=1);

use Monei\Internal\GuzzleHttp\Client;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Api\UserAgent;
use Monei\SyliusPlugin\ApplePay\DomainAssociationController;
use Monei\SyliusPlugin\ApplePay\RegisterDomainOnSaveListener;
use Monei\SyliusPlugin\Callback\MoneiNotifyPaymentProvider;
use Monei\SyliusPlugin\CommandHandler\CapturePaymentRequestHandler;
use Monei\SyliusPlugin\CommandHandler\NotifyPaymentRequestHandler;
use Monei\SyliusPlugin\CommandHandler\StatusPaymentRequestHandler;
use Monei\SyliusPlugin\CommandProvider\MoneiCommandProvider;
use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\EventListener\MoneiExceptionListener;
use Monei\SyliusPlugin\EventListener\OrderCancelGuardListener;
use Monei\SyliusPlugin\EventListener\PaymentTransitionListener;
use Monei\SyliusPlugin\Form\Type\MoneiGatewayConfigurationType;
use Monei\SyliusPlugin\Gateway\ForcePaymentRequestsListener;
use Monei\SyliusPlugin\Gateway\GatewayConfigurationProvider;
use Monei\SyliusPlugin\Grid\PaymentMethodGridMutator;
use Monei\SyliusPlugin\OrderPay\MoneiHttpResponseProvider;
use Monei\SyliusPlugin\Payment\BoundPaymentAccess;
use Monei\SyliusPlugin\Payment\CreatePaymentRequestFactory;
use Monei\SyliusPlugin\Payment\PaymentLock;
use Monei\SyliusPlugin\Payment\SessionId;
use Monei\SyliusPlugin\Payment\StatusApplier;
use Monei\SyliusPlugin\Payment\SyncContext;
use Monei\SyliusPlugin\Payment\UsableMoneiDefaultMethodResolver;
use Monei\SyliusPlugin\Payment\UsableMoneiMethodsResolver;
use Monei\SyliusPlugin\RefundPlugin\MoneiRefundStep;
use Monei\SyliusPlugin\Twig\CardBrandLogos;
use Monei\SyliusPlugin\Twig\MoneiExtension;
use Sylius\Component\Grid\Mutator\GridMutatorInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container, ContainerBuilder $builder): void {
    $services = $container->services();

    $services->set('monei.http_client', Client::class)
        ->args([['timeout' => 30, 'connect_timeout' => 10]]);

    // For calls made while a page renders.
    $services->set('monei.render_http_client', Client::class)
        ->args([['timeout' => 2, 'connect_timeout' => 2]]);

    $services->set(MoneiApiFactory::class)
        ->args([service('monei.http_client'), UserAgent::current(), service('monei.render_http_client'), service(GatewayConfigurationProvider::class)]);

    $services->set(GatewayConfigurationProvider::class)
        ->args([service(ConnectionStore::class)]);

    $services->set(UsableMoneiMethodsResolver::class)
        ->decorate('sylius.resolver.payment_methods')
        ->args([service('.inner'), service(GatewayConfigurationProvider::class)]);

    $services->set(UsableMoneiDefaultMethodResolver::class)
        ->decorate('sylius.resolver.payment_method.default')
        ->args([service('.inner'), service(UsableMoneiMethodsResolver::class)]);

    $services->set(MoneiGatewayConfigurationType::class)
        ->args([service(GatewayConfigurationProvider::class)])
        ->tag('sylius.gateway_configuration_type', ['type' => 'monei', 'label' => 'monei.gateway_factory.monei'])
        ->tag('form.type');

    $services->set(ForcePaymentRequestsListener::class)
        ->tag('doctrine.event_listener', ['event' => 'prePersist']);

    $services->set(ConnectionStore::class)
        ->args([service('doctrine.dbal.default_connection'), service('sylius.encrypter')->nullOnInvalid()]);

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
            service(SessionId::class),
        ]);

    $services->set(MoneiCommandProvider::class)
        ->tag('sylius.payment_request.command_provider', ['gateway_factory' => 'monei']);

    $services->set(MoneiHttpResponseProvider::class)
        ->args([
            service('twig'),
            service('sylius_shop.provider.order_pay.after_pay_url'),
            service(SessionId::class),
            service(GatewayConfigurationProvider::class),
            service('router'),
            service('request_stack'),
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
            service(GatewayConfigurationProvider::class),
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
        ->args([service('sylius.repository.payment'), service(GatewayConfigurationProvider::class)])
        ->tag('sylius.payment_request.payment_notify_provider');

    $services->set(NotifyPaymentRequestHandler::class)
        ->args([
            service('sylius.repository.payment_request'),
            service(PaymentLock::class),
            service(MoneiApiFactory::class),
            service(StatusApplier::class),
            service('sylius_abstraction.state_machine'),
            service(GatewayConfigurationProvider::class),
        ])
        ->tag('messenger.message_handler', ['bus' => 'sylius.payment_request.command_bus']);

    // Priority 0: above API Platform's exception listener (-96), which answers 422.
    $services->set(MoneiExceptionListener::class)
        ->args([
            service('monolog.logger.monei'),
            service('doctrine.orm.entity_manager'),
            service('translator'),
            service('router'),
            service('request_stack'),
        ])
        ->tag('kernel.event_listener', ['event' => 'kernel.exception']);

    $services->set(PaymentTransitionListener::class)
        ->args([
            service(SyncContext::class),
            service(PaymentLock::class),
            service(BoundPaymentAccess::class),
        ])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.transition.complete'])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.transition.cancel'])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_payment.transition.refund']);

    $services->set(OrderCancelGuardListener::class)
        ->args([
            service(PaymentLock::class),
            service(BoundPaymentAccess::class),
            service(StatusApplier::class),
            service('request_stack'),
            service('monolog.logger.monei'),
        ])
        ->tag('kernel.event_listener', ['event' => 'workflow.sylius_order.transition.cancel']);

    $services->set(DomainAssociationController::class)
        ->tag('controller.service_arguments');

    // The resource controller dispatches these after the flush, so a MONEI refusal never blocks the save.
    $services->set(RegisterDomainOnSaveListener::class)
        ->args([
            service(MoneiApiFactory::class),
            service('request_stack'),
            service('monolog.logger.monei'),
            service(GatewayConfigurationProvider::class),
        ])
        ->tag('kernel.event_listener', ['event' => 'sylius.payment_method.post_create'])
        ->tag('kernel.event_listener', ['event' => 'sylius.payment_method.post_update']);

    $services->set(SessionId::class)
        ->args(['%kernel.secret%']);

    $services->set(CardBrandLogos::class)
        ->args([
            service(MoneiApiFactory::class),
            service('cache.app'),
            service('monolog.logger.monei'),
        ]);

    $services->set(MoneiExtension::class)
        ->args([service(SessionId::class), service(CardBrandLogos::class), service(GatewayConfigurationProvider::class)])
        ->tag('twig.extension');

    // Grid mutators exist from sylius/grid-bundle 1.16.
    if (interface_exists(GridMutatorInterface::class)) {
        $services->set(PaymentMethodGridMutator::class)
            ->args(['%sylius.grids_definitions%'])
            ->tag('sylius.grid_mutator', ['grid' => PaymentMethodGridMutator::GRID]);
    }

    /** @var array<string, class-string> $bundles */
    $bundles = $builder->getParameter('kernel.bundles');
    if (!isset($bundles['SyliusRefundPlugin'])) {
        return;
    }

    // Before the refund plugin's credit memo (100) and refund payment (50) steps: a refusal
    // must not leave a credit memo PDF or e-mail behind (the rollback reuses its number).
    $services->set(MoneiRefundStep::class)
        ->args([
            service('sylius.repository.payment_method'),
            service('sylius.repository.order'),
            service('sylius_refund.provider.order_refunded_total'),
            service(PaymentLock::class),
            service(BoundPaymentAccess::class),
            service('request_stack'),
            service('monolog.logger.monei'),
        ])
        ->tag('sylius_refund.units_refunded.process_step', ['priority' => 200]);
};
