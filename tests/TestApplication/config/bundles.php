<?php

declare(strict_types=1);

$bundles = [
    Monei\SyliusPlugin\MoneiSyliusPlugin::class => ['all' => true],
];

// `make test-container-without-refund-plugin` checks that the plugin boots without it.
if (!filter_var($_SERVER['MONEI_TEST_WITHOUT_REFUND_PLUGIN'] ?? false, \FILTER_VALIDATE_BOOLEAN)) {
    $bundles += [
        Knp\Bundle\GaufretteBundle\KnpGaufretteBundle::class => ['all' => true],
        Knp\Bundle\SnappyBundle\KnpSnappyBundle::class => ['all' => true],
        Sylius\PdfGenerationBundle\SyliusPdfGenerationBundle::class => ['all' => true],
        Sylius\RefundPlugin\SyliusRefundPlugin::class => ['all' => true],
    ];
}

return $bundles;
