<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Controller;

use Monei\SyliusPlugin\Connect\ConnectService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

final readonly class ConnectCallbackController
{
    public function __construct(
        private ConnectService $connect,
        private Environment $twig,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $query = $request->query->all();
        $state = $query['state'] ?? '';
        $code = $query['code'] ?? '';
        $result = $this->connect->complete(\is_string($state) ? $state : '', \is_string($code) ? $code : '');

        return new Response(
            $this->twig->render('@MoneiSyliusPlugin/connect/result.html.twig', ['result' => $result]),
            [] === $result['switched'] ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK,
            ['Referrer-Policy' => 'no-referrer', 'Cache-Control' => 'no-store'],
        );
    }
}
