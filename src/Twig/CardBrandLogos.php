<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Twig;

use Monei\ApiException;
use Monei\SyliusPlugin\Api\MoneiApi;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\Gateway\MoneiMethod;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The logos of the card brands that the MONEI account accepts. The Payment step is a live
 * component that renders again on each change, so the answer is cached. Without an answer,
 * Visa and Mastercard: the logos must never name a brand the account refuses.
 */
final readonly class CardBrandLogos
{
    /** MONEI brand => file name (no extension) in public/images. */
    public const LOGOS = [
        'visa' => 'visa',
        'mastercard' => 'master_card',
        'amex' => 'amex',
        'maestro' => 'maestro',
    ];

    private const TTL = 3600;
    private const FAILURE_TTL = 300;

    public function __construct(
        private MoneiApiFactory $apiFactory,
        private CacheInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /** @return list<string> */
    public function forApiKey(string $apiKey): array
    {
        try {
            return $this->cache->get('monei_card_brands_'.hash('sha256', $apiKey), function (ItemInterface $item) use ($apiKey): array {
                try {
                    $brands = $this->apiFactory->forApiKey($apiKey)->cardBrands();
                } catch (\Throwable $e) {
                    $item->expiresAfter(self::FAILURE_TTL);

                    return $this->fallback($e instanceof ApiException ? MoneiApi::reason($e) : $e->getMessage());
                }
                $item->expiresAfter(self::TTL);
                $logos = array_values(array_intersect_key(self::LOGOS, array_flip($brands)));

                return [] !== $logos ? $logos : $this->fallback('no known brand in ['.implode(', ', $brands).']');
            });
        } catch (\Throwable $e) {
            return $this->fallback($e->getMessage());
        }
    }

    /** @return list<string> */
    private function fallback(string $reason): array
    {
        $this->logger->warning('MONEI card brands unavailable ({reason}): showing Visa and Mastercard.', ['reason' => $reason]);

        return MoneiMethod::Card->logos();
    }
}
