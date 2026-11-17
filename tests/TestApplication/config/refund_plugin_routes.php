<?php

declare(strict_types=1);

use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

// The routing loader has no container: the same switch as bundles.php.
return static function (RoutingConfigurator $routes): void {
    if (filter_var($_SERVER['MONEI_TEST_WITHOUT_REFUND_PLUGIN'] ?? false, \FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    $routes->import('@SyliusRefundPlugin/config/routes.yaml');
};
