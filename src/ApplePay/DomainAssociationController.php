<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\ApplePay;

use Symfony\Component\HttpFoundation\Response;

/** Serves MONEI's Apple Pay domain association file, which Apple fetches to verify a registered domain. */
final class DomainAssociationController
{
    private const FILE = __DIR__.'/../../public/apple-developer-merchantid-domain-association';

    public function __invoke(): Response
    {
        $content = file_get_contents(self::FILE);
        if (false === $content) {
            throw new \RuntimeException(\sprintf('Cannot read "%s".', self::FILE));
        }

        return new Response($content, Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
