<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Api;

use Monei\SyliusPlugin\Api\UserAgent;
use PHPUnit\Framework\TestCase;

final class UserAgentTest extends TestCase
{
    public function testTaggedVersion(): void
    {
        self::assertSame(
            'MONEI/Sylius/2.0.1 (Sylius v2.3.2; PHP v8.3.36)',
            UserAgent::build('v2.0.1', '2.3.2', '8.3.36'),
        );
    }

    /** MONEI parses the plugin version as semver. */
    public function testBranchInstallSendsZeroVersion(): void
    {
        self::assertSame(
            'MONEI/Sylius/0.0.0 (Sylius v2.3.2; PHP v8.3.36)',
            UserAgent::build('dev-main', '2.3.2', '8.3.36'),
        );
    }
}
