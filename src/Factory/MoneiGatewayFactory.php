<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Factory;

use Monei\SyliusPlugin\Action\CaptureAction;
use Monei\SyliusPlugin\Action\ConvertPaymentAction;
use Monei\SyliusPlugin\Action\NotifyAction;
use Monei\SyliusPlugin\Action\RefundAction;
use Monei\SyliusPlugin\Action\StatusAction;
use Monei\SyliusPlugin\Client\MoneiApiClient;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\GatewayFactory;

final class MoneiGatewayFactory extends GatewayFactory
{
    public const FACTORY_NAME = 'monei';

    public const INTEGRATION_REDIRECT = 'redirect';
    public const INTEGRATION_COMPONENT = 'component';

    protected function populateConfig(ArrayObject $config): void
    {
        $config->defaults([
            'payum.factory_name' => self::FACTORY_NAME,
            'payum.factory_title' => 'MONEI',

            'payum.action.capture' => new CaptureAction(),
            'payum.action.convert_payment' => new ConvertPaymentAction(),
            'payum.action.status' => new StatusAction(),
            'payum.action.refund' => new RefundAction(),
            'payum.action.notify' => new NotifyAction(),
        ]);

        if (false === isset($config['payum.api'])) {
            $config['payum.default_options'] = [
                'api_key' => '',
                'account_id' => '',
                'integration_type' => self::INTEGRATION_REDIRECT,
                'sandbox' => true,
            ];
            $config->defaults($config['payum.default_options']);

            $config['payum.required_options'] = [
                'api_key',
                'account_id',
            ];

            $config['payum.api'] = function (ArrayObject $config): MoneiApiClient {
                $config->validateNotEmpty($config['payum.required_options']);

                return new MoneiApiClient(
                    (string) $config['api_key'],
                    (string) $config['account_id'],
                );
            };
        }
    }
}
