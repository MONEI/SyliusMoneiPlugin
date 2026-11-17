<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Http;

use Symfony\Component\HttpFoundation\Request;

final class ApiRequest
{
    /** An API Platform operation: it answers with a status code, never a flash. */
    public static function matches(Request $request): bool
    {
        return $request->attributes->has('_api_resource_class');
    }
}
