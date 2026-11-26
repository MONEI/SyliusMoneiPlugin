<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Connect;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\RetryableException;
use Monei\Connect;
use Monei\ConnectException;
use Monei\Internal\GuzzleHttp\ClientInterface;
use Monei\SyliusPlugin\Api\MoneiApiFactory;
use Monei\SyliusPlugin\ApplePay\ApplePayDomainRegistrar;
use Symfony\Component\Clock\ClockInterface;

/**
 * @phpstan-type Mode 'live'|'test'
 * @phpstan-type Result array{switched: list<Mode>, problems: list<array{mode: Mode|null, code: string}>}
 */
final readonly class ConnectService
{
    public const CLIENT = 'sylius';
    public const STATE_TTL = 1200;

    /**
     * @param \Closure(string, string, string, string, string): array<string, array<string, string>> $exchange
     * @param \Closure(string): int                                                                  $probe
     * @param \Closure(Mode, string): bool                                                           $registerApplePay
     */
    public function __construct(
        private Connection $connection,
        private ConnectionStore $store,
        private ClockInterface $clock,
        private \Closure $exchange,
        private \Closure $probe,
        private \Closure $registerApplePay,
        private string $baseUrl = Connect::BASE_URL,
    ) {
    }

    public static function create(
        Connection $connection,
        ConnectionStore $store,
        ClockInterface $clock,
        ClientInterface $httpClient,
        MoneiApiFactory $apiFactory,
        ApplePayDomainRegistrar $registrar,
        ?string $baseUrl,
    ): self {
        return new self(
            $connection,
            $store,
            $clock,
            static fn (string $code, string $verifier, string $client, string $uri, string $base): array => Connect::exchange($code, $verifier, $client, $uri, $base, $httpClient),
            static fn (string $key): int => $apiFactory->forApiKey($key)->probe(),
            $registrar->registerConnected(...),
            $baseUrl ?: Connect::BASE_URL,
        );
    }

    public function begin(string $redirectUri): string
    {
        $now = $this->clock->now();
        $start = Connect::start(self::CLIENT, $redirectUri, $this->baseUrl);
        $this->store->ensure();
        $this->connection->transactional(function () use ($now, $start, $redirectUri): void {
            $generation = $this->store->lock()->generation;
            $this->store->deleteExpiredStates(self::cutoff($now));
            $this->store->insertState($start['state'], $start['verifier'], $redirectUri, $generation, $now);
        });

        return $start['url'];
    }

    /** @return Result */
    public function complete(string $state, string $code): array
    {
        if ('' === $code) {
            return self::failed('missingCode');
        }

        try {
            $entry = preg_match('/^[A-Za-z0-9_-]{43}\z/', $state) ? $this->store->readState($state) : null;
            // Only the callback that removed the row can exchange the code.
            if (null === $entry || 1 !== $this->store->deleteState($state)) {
                return self::failed('unknownState');
            }
            if ($entry->createdAt < self::cutoff($this->clock->now())) {
                return self::failed('expiredState');
            }

            return $this->exchangeAndSwap($entry, $code);
        } catch (RetryableException) {
            return self::failed('temporarily_unavailable');
        }
    }

    public function disconnect(): void
    {
        $this->store->clear();
    }

    /** @return Result */
    private function exchangeAndSwap(PendingConnectState $entry, string $code): array
    {
        $before = $this->store->read();
        if ($before->generation !== $entry->generation) {
            return self::failed('disconnected');
        }

        $result = ['switched' => [], 'problems' => []];
        $proven = [];
        $liveAccount = null;
        try {
            $returned = ($this->exchange)($code, $entry->verifier, self::CLIENT, $entry->redirectUri, $this->baseUrl);
            $liveAccount = $returned['live']['accountId'] ?? null;
            foreach (['live', 'test'] as $mode) {
                if (isset($returned['errors'][$mode])) {
                    $result['problems'][] = ['mode' => $mode, 'code' => self::brokerProblem($returned['errors'][$mode])];
                }
                if (!isset($returned[$mode]) || self::otherAccount($before, $liveAccount)) {
                    continue;
                }

                $values = $returned[$mode];
                $problem = $this->probeMode($mode, $values);
                if (null === $problem) {
                    $proven[$mode] = ['accountId' => $values['accountId'], 'apiKey' => $values['apiKey'], 'signingSecret' => $values['signingSecret']];
                } else {
                    $result['problems'][] = ['mode' => $mode, 'code' => $problem];
                }
            }
        } catch (ConnectException $e) {
            $result = self::failed(self::brokerProblem($e->getError()));
        }

        $result = $this->connection->transactional(function () use ($before, $entry, $liveAccount, $proven, $result): array {
            $current = $this->store->lock();
            if ($current->generation !== $entry->generation) {
                return self::failed('disconnected');
            }
            if (self::otherAccount($before, $liveAccount) || self::otherAccount($current, $liveAccount)) {
                $result['problems'][] = ['mode' => null, 'code' => 'otherAccount'];
            } else {
                foreach ($proven as $mode => $values) {
                    $this->store->writeMode('live' === $mode, $values['accountId'], $values['apiKey'], $values['signingSecret']);
                    $result['switched'][] = $mode;
                }
            }
            $this->store->writeResult($result);

            return $result;
        });

        $swapResult = $result;
        foreach ($proven as $mode => $values) {
            if (\in_array($mode, $result['switched'], true) && !($this->registerApplePay)($mode, $values['apiKey'])) {
                $result['problems'][] = ['mode' => $mode, 'code' => 'applePayRegistrationFailed'];
            }
        }

        if ($result !== $swapResult) {
            try {
                $this->connection->transactional(function () use ($entry, $swapResult, $proven, $result): void {
                    $current = $this->store->lock();
                    // A later connect or Disconnect may have won while registration was in flight.
                    if ($current->generation === $entry->generation && $current->lastResult == $swapResult
                        && (!isset($proven['live']) || $current->liveApiKey === $proven['live']['apiKey'])
                        && (!isset($proven['test']) || $current->testApiKey === $proven['test']['apiKey'])) {
                        $this->store->writeResult($result);
                    }
                });
            } catch (RetryableException) {
                // The swap is already committed and the registration refusal was logged.
            }
        }

        return $result;
    }

    /** @param array<string, string> $values */
    private function probeMode(string $mode, array $values): ?string
    {
        if ('' === ($values['accountId'] ?? '') || !str_starts_with($values['apiKey'] ?? '', 'mk_'.$mode.'_')
            || !str_starts_with($values['signingSecret'] ?? '', 'mksec_') || 'mksec_' === $values['signingSecret']) {
            return 'keyRejected';
        }

        $status = ($this->probe)($values['apiKey']);

        return match (true) {
            ($status >= 200 && $status < 300) || 400 === $status => null,
            401 === $status || 403 === $status => 'keyRejected',
            default => 'temporarily_unavailable',
        };
    }

    private static function brokerProblem(string $code): string
    {
        return match ($code) {
            'blocked', 'ineligible', 'cap', 'not_approved', 'temporarily_unavailable', 'access_denied', 'invalid_request', 'invalid_client', 'invalid_grant' => $code,
            default => 'generic',
        };
    }

    private static function otherAccount(ConnectionSnapshot $connection, ?string $liveAccount): bool
    {
        return null !== $liveAccount && null !== $connection->liveAccountId && $connection->liveAccountId !== $liveAccount;
    }

    private static function cutoff(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('-'.self::STATE_TTL.' seconds');
    }

    /** @return Result */
    private static function failed(string $code): array
    {
        return ['switched' => [], 'problems' => [['mode' => null, 'code' => $code]]];
    }
}
