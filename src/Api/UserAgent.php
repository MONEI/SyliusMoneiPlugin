<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Api;

use Composer\InstalledVersions;
use Sylius\Bundle\CoreBundle\SyliusCoreBundle;

/** `MONEI/Sylius/<plugin> (Sylius v<x>; PHP v<y>)`, the format of the other MONEI plugins. */
final class UserAgent
{
    public const PACKAGE = 'monei/sylius-monei-plugin';

    public static function build(?string $pluginVersion, string $syliusVersion, string $phpVersion): string
    {
        return sprintf(
            'MONEI/Sylius/%s (Sylius v%s; PHP v%s)',
            self::semver($pluginVersion),
            self::semver($syliusVersion),
            self::semver($phpVersion),
        );
    }

    public static function current(): string
    {
        $pluginVersion = InstalledVersions::isInstalled(self::PACKAGE)
            ? InstalledVersions::getPrettyVersion(self::PACKAGE)
            : null;

        return self::build($pluginVersion, SyliusCoreBundle::VERSION, \PHP_VERSION);
    }

    /** `v2.0.1` and `2.0.1-dev` give `2.0.1`; a branch such as `dev-main` gives `0.0.0`. */
    private static function semver(?string $version): string
    {
        if (null !== $version && 1 === preg_match('/^v?(\d+\.\d+\.\d+)/', $version, $match)) {
            return $match[1];
        }

        return '0.0.0';
    }
}
