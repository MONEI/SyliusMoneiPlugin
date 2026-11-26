<?php

declare(strict_types=1);

namespace Monei\SyliusPlugin\Tests\Unit\Connect;

use Doctrine\DBAL\Connection;
use Monei\ConnectException;
use Monei\SyliusPlugin\Connect\ConnectionStore;
use Monei\SyliusPlugin\Connect\ConnectService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ConnectServiceTest extends TestCase
{
    private const URI = 'https://shop.example/monei/connect';
    private const STATE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_STATE = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const LIVE = ['accountId' => 'live-account', 'apiKey' => 'mk_live_new', 'signingSecret' => 'mksec_live_new'];
    private const TEST = ['accountId' => 'new-test-account', 'apiKey' => 'mk_test_new', 'signingSecret' => 'mksec_test_new'];

    private ConnectionStore $store;
    private Connection $db;
    private MockClock $clock;
    private array $states = [];
    private array $row = [
        'generation' => 0, 'last_result' => null,
        'live_account_id' => 'live-account', 'live_api_key' => 'mk_live_old', 'live_signing_secret' => 'mksec_live_old',
        'test_account_id' => 'old-test-account', 'test_api_key' => 'mk_test_old', 'test_signing_secret' => 'mksec_test_old',
    ];
    private bool $deleteLostRace = false;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-26 12:00:00 UTC');
        $this->db = $this->createMock(Connection::class);
        $this->db->method('isTransactionActive')->willReturn(true);
        $this->db->method('transactional')->willReturnCallback(fn (\Closure $operation): mixed => $operation($this->db));
        $this->db->method('fetchOne')->willReturnCallback(fn (string $sql, array $args): int|string|false => str_contains($sql, 'monei_connect_state') ? ($this->states[$args[0]]['state'] ?? false) : 1);
        $this->db->method('fetchAssociative')->willReturnCallback(fn (string $sql, array $args): array|false => str_contains($sql, 'monei_connect_state') ? ($this->states[$args[0]] ?? false) : $this->row);
        $this->db->method('insert')->willReturnCallback(function (string $table, array $data): int {
            $data['created_at'] = $data['created_at']->format('Y-m-d H:i:s');
            $this->states[$data['state']] = $data;

            return 1;
        });
        $this->db->method('delete')->willReturnCallback(function (string $table, array $criteria): int {
            unset($this->states[$criteria['state']]);

            return $this->deleteLostRace ? 0 : 1;
        });
        $this->db->method('update')->willReturnCallback(function (string $table, array $data): int {
            if (isset($data['last_result'])) {
                $data['last_result'] = json_encode($data['last_result'], \JSON_THROW_ON_ERROR);
            }
            $this->row = array_replace($this->row, $data);

            return 1;
        });
        $this->db->method('executeStatement')->willReturnCallback(function (string $sql, array $args = []): int {
            $this->states = [] === $args ? [] : array_filter($this->states, static fn (array $entry): bool => $entry['created_at'] >= $args[0]->format('Y-m-d H:i:s'));

            return 1;
        });
        $this->store = new ConnectionStore($this->db, null);
    }

    public function testBeginPrunesAbandonedStatesAndBindsTheRedirectAndGeneration(): void
    {
        $this->seed(self::STATE, '2026-11-26 11:39:59');
        $this->seed(self::OTHER_STATE, '2026-11-26 11:40:01');
        $this->row['generation'] = 3;

        $url = $this->service()->begin(self::URI);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame('sylius', $query['client_id']);
        self::assertSame(self::URI, $query['redirect_uri']);
        self::assertNull($this->store->readState(self::STATE));
        self::assertNotNull($this->store->readState(self::OTHER_STATE));
        $pending = $this->store->readState($query['state']);
        self::assertSame(3, $pending?->generation);
        self::assertSame(self::URI, $pending?->redirectUri);
    }

    public function testOnlyTheCallbackThatDeletesAFreshStateCanExchange(): void
    {
        $service = $this->service();
        self::assertSame('unknownState', $service->complete(self::STATE, 'code')['problems'][0]['code']);
        $this->seed(self::STATE, '2026-11-26 11:39:59');
        self::assertSame('expiredState', $service->complete(self::STATE, 'code')['problems'][0]['code']);
        self::assertSame('unknownState', $service->complete(self::STATE, 'code')['problems'][0]['code']);

        $this->seed();
        $this->deleteLostRace = true;
        self::assertSame('unknownState', $service->complete(self::STATE, 'code')['problems'][0]['code']);
        self::assertNull($this->store->read()->lastResult);
    }

    public function testFailedExchangeConsumesTheStateAndRecordsTheBrokerCode(): void
    {
        $this->seed();
        $service = $this->service(function (): never {
            self::assertNull($this->store->readState(self::STATE));
            throw new ConnectException('invalid_grant');
        });
        $result = $service->complete(self::STATE, 'code');

        self::assertSame(['switched' => [], 'problems' => [['mode' => null, 'code' => 'invalid_grant']]], $result);
        self::assertSame($result, $this->store->read()->lastResult);
        self::assertTrue('mk_live_old' === $this->store->read()->liveApiKey && 'mk_test_old' === $this->store->read()->testApiKey);
        self::assertSame('unknownState', $service->complete(self::STATE, 'code')['problems'][0]['code']);
    }

    public function testAnErrorsOnlyExchangeKeepsBothWorkingModesAndEachBrokerCode(): void
    {
        $this->seed();
        $result = $this->service(static fn (): array => ['errors' => ['live' => 'not_approved', 'test' => 'cap']])->complete(self::STATE, 'code');

        self::assertSame([], $result['switched']);
        self::assertSame([['mode' => 'live', 'code' => 'not_approved'], ['mode' => 'test', 'code' => 'cap']], $result['problems']);
        self::assertSame($result, $this->store->read()->lastResult);
        self::assertTrue('mk_live_old' === $this->store->read()->liveApiKey && 'mk_test_old' === $this->store->read()->testApiKey);
    }

    #[DataProvider('probeStatuses')]
    public function testProbeOnlyReplacesCredentialsOnProof(int $status, ?string $problem): void
    {
        $this->seed();
        $result = $this->service(
            static fn (): array => ['test' => self::TEST],
            static fn (): int => $status,
            static fn (): bool => true,
        )->complete(self::STATE, 'code');

        self::assertSame(null === $problem ? ['test'] : [], $result['switched']);
        self::assertSame(null === $problem ? [] : [['mode' => 'test', 'code' => $problem]], $result['problems']);
        self::assertSame(null === $problem ? 'new-test-account' : 'old-test-account', $this->store->read()->testAccountId);
        self::assertTrue('mk_live_old' === $this->store->read()->liveApiKey);
    }

    public static function probeStatuses(): iterable
    {
        yield 'mode without enabled providers' => [400, null];
        yield 'unauthorized' => [401, 'keyRejected'];
        yield 'forbidden' => [403, 'keyRejected'];
        yield 'upstream outage' => [503, 'temporarily_unavailable'];
        yield 'no response' => [0, 'temporarily_unavailable'];
    }

    public function testAProvenTestKeyCanReplaceItsAccountWhenLiveIsRejected(): void
    {
        $this->seed();
        $result = $this->service(
            static fn (): array => ['test' => self::TEST, 'live' => self::LIVE],
            static fn (string $key): int => 'mk_live_new' === $key ? 401 : 204,
            static fn (): bool => true,
        )->complete(self::STATE, 'code');

        self::assertSame(['switched' => ['test'], 'problems' => [['mode' => 'live', 'code' => 'keyRejected']]], $result);
        self::assertTrue('mk_live_old' === $this->store->read()->liveApiKey && 'mk_test_new' === $this->store->read()->testApiKey);
    }

    public function testAConflictingLiveAccountBlocksTheTestSwapAsWell(): void
    {
        $this->seed();
        $result = $this->service(static fn (): array => [
            'live' => ['accountId' => 'other-account'] + self::LIVE, 'test' => self::TEST,
        ])->complete(self::STATE, 'code');

        self::assertSame(['switched' => [], 'problems' => [['mode' => null, 'code' => 'otherAccount']]], $result);
        self::assertTrue('mk_live_old' === $this->store->read()->liveApiKey && 'mk_test_old' === $this->store->read()->testApiKey);
    }

    public function testLiveAccountIsCheckedAgainAfterProbingBeforeEitherModeIsWritten(): void
    {
        $this->store->clear();
        $this->store->insertState(self::STATE, 'verifier', self::URI, 1, $this->clock->now());
        $result = $this->service(
            static fn (): array => ['live' => self::LIVE, 'test' => self::TEST],
            function (string $key): int {
                if ('mk_live_new' === $key) {
                    $this->store->writeMode(true, 'other-account', 'mk_live_other', 'mksec_other');
                }

                return 200;
            },
        )->complete(self::STATE, 'code');

        self::assertSame(['switched' => [], 'problems' => [['mode' => null, 'code' => 'otherAccount']]], $result);
        self::assertSame('other-account', $this->store->read()->liveAccountId);
        self::assertNull($this->store->read()->testAccountId);
    }

    public function testDisconnectDuringProbeInvalidatesTheConsumedStateAndDeletesOtherStates(): void
    {
        $this->seed();
        $this->seed(self::OTHER_STATE);
        $service = $this->service(
            static fn (): array => ['test' => self::TEST],
            function (): int {
                $this->service()->disconnect();

                return 200;
            },
        );
        self::assertSame(['switched' => [], 'problems' => [['mode' => null, 'code' => 'disconnected']]], $service->complete(self::STATE, 'code'));
        self::assertSame([], $this->states);
        $connection = $this->store->read();
        self::assertSame(1, $connection->generation);
        self::assertNull($connection->liveApiKey);
        self::assertNull($connection->testApiKey);
        self::assertNull($connection->lastResult);
    }

    public function testAModeWithoutASigningSecretIsRejectedBeforeProbing(): void
    {
        $this->seed();
        $result = $this->service(static fn (): array => ['test' => ['signingSecret' => ''] + self::TEST])->complete(self::STATE, 'code');

        self::assertSame(['switched' => [], 'problems' => [['mode' => 'test', 'code' => 'keyRejected']]], $result);
        self::assertTrue('mk_test_old' === $this->store->read()->testApiKey);
    }

    public function testApplePayUsesTheNewKeyAndRegistrationFailureKeepsTheSwap(): void
    {
        $this->seed();
        $result = $this->service(
            static fn (): array => ['test' => self::TEST],
            static fn (): int => 200,
            function (string $mode, string $key): bool {
                self::assertSame('test', $mode);
                self::assertTrue('mk_test_new' === $key && $key === $this->store->read()->testApiKey);

                return false;
            },
        )->complete(self::STATE, 'code');

        self::assertSame(['switched' => ['test'], 'problems' => [['mode' => 'test', 'code' => 'applePayRegistrationFailed']]], $result);
        self::assertSame($result, $this->store->read()->lastResult);
        self::assertTrue('mk_test_new' === $this->store->read()->testApiKey);
    }

    private function seed(string $state = self::STATE, string $createdAt = '2026-11-26 11:55:00'): void
    {
        $this->store->insertState($state, 'verifier', self::URI, 0, new \DateTimeImmutable($createdAt));
    }

    private function service(?\Closure $exchange = null, ?\Closure $probe = null, ?\Closure $register = null): ConnectService
    {
        return new ConnectService(
            $this->db,
            $this->store,
            $this->clock,
            $exchange ?? static fn () => self::fail('The callback must not exchange a code.'),
            $probe ?? static fn () => self::fail('The callback must not probe a key.'),
            $register ?? static fn () => self::fail('The callback must not register Apple Pay.'),
        );
    }
}
