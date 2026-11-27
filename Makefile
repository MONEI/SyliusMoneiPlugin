# Every command runs in Docker. Docker may be rootless: containers run as root.
COMPOSE = docker compose
PHP = $(COMPOSE) run --rm php
CONSOLE = vendor/bin/console -e test

# Lowest supported versions: PHP 8.2 and Sylius 2.2.13, in a copy of the repo under var/lowest
# with its own vendor, encryption key and database.
LOWEST_DIR = var/lowest
LOWEST_SYLIUS = 2.2.13
PHP_LOWEST = PHP_IMAGE_TAG=8.2-alpine $(COMPOSE) run --rm -w /app/$(LOWEST_DIR) \
	-e DATABASE_URL='mysql://root:root@mysql:3306/sylius_monei_lowest_%kernel.environment%' php

DB_SETUP = $(CONSOLE) doctrine:database:create --if-not-exists && $(CONSOLE) doctrine:schema:update --force
MIGRATION_DATABASE_URL = mysql://root:root@mysql:3306/sylius_monei_migrations_%kernel.environment%
KEY = $(CONSOLE) sylius:payment:generate-key --no-interaction

.PHONY: install test test-unit test-functional test-container-without-refund-plugin test-container-without-encryption test-migrations analyse cs cs-fix test-lowest down

install:
	$(PHP) sh -c 'composer install --no-interaction --no-progress && $(KEY) && $(DB_SETUP)'

test: test-unit test-functional

test-unit:
	$(PHP) vendor/bin/phpunit --testsuite unit

test-functional:
	$(PHP) sh -c '$(DB_SETUP) && vendor/bin/phpunit --testsuite functional'

# The test kernel tracks only its own bundles.php, so this run needs its own cache.
test-container-without-refund-plugin:
	$(COMPOSE) run --rm -e MONEI_TEST_WITHOUT_REFUND_PLUGIN=1 -e APP_CACHE_DIR=/app/var/cache-without-refund-plugin \
		php vendor/bin/phpunit --testsuite functional --filter ContainerTest

test-container-without-encryption:
	$(COMPOSE) run --rm -e MONEI_TEST_WITHOUT_ENCRYPTION=1 -e APP_CACHE_DIR=/app/var/cache-without-encryption \
		php sh -c '$(DB_SETUP) && vendor/bin/phpunit --testsuite without-encryption'

test-migrations:
	$(PHP) env DATABASE_URL='$(MIGRATION_DATABASE_URL)' sh -ec '\
		trap "$(CONSOLE) doctrine:database:drop --force --if-exists" EXIT; \
		$(CONSOLE) doctrine:database:drop --force --if-exists; \
		$(CONSOLE) doctrine:database:create; \
		$(CONSOLE) doctrine:migrations:migrate --no-interaction; \
		diff_sql=$$($(CONSOLE) doctrine:schema:update --dump-sql); \
		if printf "%s\n" "$$diff_sql" | grep -i monei_; then exit 1; fi'

analyse:
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs:
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(PHP) vendor/bin/php-cs-fixer fix

test-lowest:
	mkdir -p $(LOWEST_DIR)
	find $(LOWEST_DIR) -mindepth 1 -maxdepth 1 ! -name vendor ! -name var ! -name composer.lock -exec rm -rf {} +
	tar --exclude=./vendor --exclude=./var --exclude=./.git --exclude=./docs --exclude=./composer.lock -cf - . | tar -xf - -C $(LOWEST_DIR)
	$(PHP_LOWEST) sh -c 'composer require sylius/sylius:$(LOWEST_SYLIUS) --no-update --no-interaction \
		&& composer update --no-interaction --no-progress --no-audit \
		&& $(KEY) && $(DB_SETUP) \
		&& vendor/bin/phpunit --testsuite unit && vendor/bin/phpunit --testsuite functional'

down:
	$(COMPOSE) down --volumes
