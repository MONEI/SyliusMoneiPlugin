<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Controller\Admin;

use Monei\SyliusPlugin\Connect\CallbackUrl;
use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\Connect\ConnectService;
use Monei\SyliusPlugin\Gateway\GatewayConfiguration;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Payment\Repository\PaymentMethodRepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

final readonly class ConnectController
{
    /** @param PaymentMethodRepositoryInterface<PaymentMethodInterface> $paymentMethods */
    public function __construct(
        private CallbackUrl $callbackUrl,
        private ConnectionStore $store,
        private ConnectService $connect,
        private PaymentMethodRepositoryInterface $paymentMethods,
        private CsrfTokenManagerInterface $csrf,
        private UrlGeneratorInterface $urls,
        private Environment $twig,
    ) {
    }

    public function index(Request $request): Response
    {
        $connection = $this->store->read();
        $modes = $manualMethods = [];
        foreach (['live' => [$connection->liveAccountId, $connection->liveApiKey], 'test' => [$connection->testAccountId, $connection->testApiKey]] as $mode => [$accountId, $key]) {
            if (null !== $key) {
                $modes[$mode] = ['accountId' => $accountId, 'keySuffix' => substr($key, -4)];
            }
        }
        foreach ($this->paymentMethods->findBy([], ['code' => 'ASC']) as $method) {
            if (GatewayConfiguration::isMonei($method)
                && GatewayConfiguration::MANUAL === ($method->getGatewayConfig()?->getConfig()[GatewayConfiguration::CREDENTIALS] ?? GatewayConfiguration::MANUAL)) {
                $manualMethods[] = ['id' => $method->getId(), 'name' => $method->getName() ?: $method->getCode()];
            }
        }

        return new Response($this->twig->render('@MoneiSyliusPlugin/admin/connect/index.html.twig', [
            'callbackUrl' => $this->callbackUrl->forRequest($request),
            'modes' => $modes,
            'lastResult' => $connection->lastResult,
            'manualMethods' => $manualMethods,
        ]), headers: ['Cache-Control' => 'no-store']);
    }

    public function start(Request $request): Response
    {
        $this->checkToken($request, 'monei_connect_start');
        $url = $this->callbackUrl->forRequest($request);
        if (null === $url) {
            throw new BadRequestHttpException('No eligible MONEI callback URL.');
        }

        return new RedirectResponse($this->connect->begin($url), Response::HTTP_SEE_OTHER, ['Cache-Control' => 'no-store']);
    }

    public function disconnect(Request $request): Response
    {
        $this->checkToken($request, 'monei_connect_disconnect');
        $this->connect->disconnect();

        return new RedirectResponse($this->urls->generate('monei_sylius_admin_connect'), Response::HTTP_SEE_OTHER);
    }

    private function checkToken(Request $request, string $id): void
    {
        $value = $request->request->get('_token');
        if (!\is_string($value) || !$this->csrf->isTokenValid(new CsrfToken($id, $value))) {
            throw new AccessDeniedHttpException();
        }
    }
}
