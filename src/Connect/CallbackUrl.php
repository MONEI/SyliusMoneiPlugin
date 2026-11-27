<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Connect;

use Monei\Connect;
use Sylius\Component\Channel\Model\ChannelInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class CallbackUrl
{
    /** @param ChannelRepositoryInterface<ChannelInterface> $channels */
    public function __construct(private ChannelRepositoryInterface $channels)
    {
    }

    public function forRequest(Request $request): ?string
    {
        $hosts = [];
        foreach ($this->channels->findBy(['enabled' => true], ['code' => 'ASC']) as $channel) {
            $hosts[] = $channel->getHostname();
        }
        if ($request->isSecure()) {
            $hosts[] = $request->getHttpHost();
        }

        foreach ($hosts as $host) {
            if (null === $host || '' === $host) {
                continue;
            }

            try {
                $uri = Connect::canonicalRedirectUri('https://'.$host.$request->getBaseUrl().'/monei/connect');
            } catch (\InvalidArgumentException) {
                continue;
            }

            if (null === Connect::callbackProblem(ConnectService::CLIENT, $uri)) {
                return $uri;
            }
        }

        return null;
    }
}
