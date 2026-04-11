<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin;

use Symfony\Component\HttpKernel\Bundle\Bundle;

final class MoneiSyliusPlugin extends Bundle
{
    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
