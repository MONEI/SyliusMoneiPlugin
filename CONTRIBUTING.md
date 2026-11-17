# Contributing to SyliusMoneiPlugin

Thank you for considering contributing to the MONEI Sylius plugin.

## How to contribute

1. **Fork** this repository.
2. **Create a branch** from `main` (`git checkout -b feat/my-feature`).
3. **Write tests** for your change. Each test must fail on a real bug that no other test catches.
4. **Run the checks** before you commit (see [Development setup](#development-setup)).
5. **Commit** with [Conventional Commits](https://www.conventionalcommits.org/) messages, for example `fix: keep the payment new when MONEI refuses the create`.
6. **Push** to your fork and open a **pull request**.

## Development setup

You need Docker with Compose. You do not need PHP on your computer: every command runs in the `php` container of `compose.yaml`, with a MySQL 8.4 container for the functional tests.

```bash
git clone git@github.com:MONEI/SyliusMoneiPlugin.git
cd SyliusMoneiPlugin
make install
make test
```

| Command | Description |
| --- | --- |
| `make install` | Composer install, payment encryption key, test database schema. |
| `make test` | Unit and functional tests. |
| `make test-unit` | Unit tests only. |
| `make test-functional` | Functional tests only (Sylius test application with MySQL). |
| `make test-lowest` | Unit and functional tests on PHP 8.2 and Sylius 2.2.12, in a copy of the repo under `var/lowest`. |
| `make test-container-without-refund-plugin` | Checks that the plugin boots without `sylius/refund-plugin`. |
| `make analyse` | PHPStan, level 8. |
| `make cs` | PHP CS Fixer, dry run. |
| `make cs-fix` | PHP CS Fixer, fixes the files. |
| `make down` | Removes the containers and the Composer cache volume. MySQL keeps its data in memory (tmpfs), so it keeps no data after a stop. |

Unit and functional tests never call MONEI: they mock its HTTP API.

CI also checks `composer.json` and the service container. To run these checks locally:

```bash
docker compose run --rm php composer validate --strict
docker compose run --rm php vendor/bin/console -e test lint:container
```

## End-to-end tests

The e2e suite pays against the MONEI test API in a real Sylius store in Docker. It needs Docker with Compose, Node.js 22.18 or later and pnpm, and `e2e/.env` with a **test mode** MONEI API key (copy `e2e/.env.example`). Never use a live key. Never commit `e2e/.env`.

```bash
pnpm --dir e2e install
pnpm --dir e2e exec playwright install chromium
pnpm --dir e2e e2e:start   # build, boot, seed, open the tunnel
pnpm --dir e2e e2e         # run the specs
pnpm --dir e2e e2e:stop    # remove the stack
```

See [e2e/README.md](e2e/README.md) for the options and the secret rules. CI runs the suite every night, and on pull requests from branches of this repository. Pull requests from forks do not get the secrets, so CI skips the e2e job for them. Maintainers: the suite reads the test mode key from the `MONEI_TEST_API_KEY` repository secret.

All e2e runs share one MONEI sandbox, so they run one at a time. GitHub keeps only one waiting run: when a third run starts, it cancels the waiting one. The `CI` check of that pull request then fails without a defect. Run the cancelled jobs again.

## Reporting issues

Use [GitHub Issues](https://github.com/MONEI/SyliusMoneiPlugin/issues). Include:

- Sylius version
- PHP version
- Steps to reproduce
- Expected and actual behaviour

Do not put API keys, account ids or customer data in an issue.

## Code of Conduct

Be respectful. We follow the [Contributor Covenant](https://www.contributor-covenant.org/version/2/1/code_of_conduct/).
