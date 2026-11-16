<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\EventListener;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

final class Flash
{
    /**
     * Adds a flash to the main request's session. A console run and an API request get none.
     *
     * @param string|array{message: string, parameters: array<string, string|int>} $message a translation key of the `flashes` domain
     */
    public static function add(RequestStack $requestStack, string $type, string|array $message): void
    {
        $request = $requestStack->getMainRequest();
        if (null === $request || ApiRequest::matches($request) || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
