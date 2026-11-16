# Every command runs in Docker. Docker may be rootless: containers run as root.
COMPOSE = docker compose
PHP = $(COMPOSE) run --rm php
CONSOLE = vendor/bin/console -e test

# Lowest supported versions: PHP 8.2 and Sylius 2.2.9, in a copy of the repo under var/lowest
# with its own vendor, encryption key and database.
LOWEST_DIR = var/lowest
LOWEST_SYLIUS = 2.2.9
PHP_LOWEST = PHP_IMAGE_TAG=8.2-alpine $(COMPOSE) run --rm -w /app/$(LOWEST_DIR) \
	-e DATABASE_URL='mysql://root:root@mysql:3306/sylius_monei_lowest_%kernel.environment%' php

DB_SETUP = $(CONSOLE) doctrine:database:create --if-not-exists && $(CONSOLE) doctrine:schema:update --force
KEY = $(CONSOLE) sylius:payment:generate-key --no-interaction

.PHONY: install test test-unit test-functional analyse cs cs-fix test-lowest down

install:
	$(PHP) sh -c 'composer install --no-interaction --no-progress && $(KEY) && $(DB_SETUP)'

test: test-unit test-functional

test-unit:
	$(PHP) vendor/bin/phpunit --testsuite unit

test-functional:
	$(PHP) sh -c '$(DB_SETUP) && vendor/bin/phpunit --testsuite functional'

analyse:
	$(PHP) vendor/bin/phpstan analyse --memory-limit=1G

cs:
	$(PHP) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(PHP) vendor/bin/php-cs-fixer fix

# Sylius 2.2.9 has open security advisories; COMPOSER_NO_BLOCKING lets Composer install it for this test.
test-lowest:
	mkdir -p $(LOWEST_DIR)
	find $(LOWEST_DIR) -mindepth 1 -maxdepth 1 ! -name vendor ! -name var ! -name composer.lock -exec rm -rf {} +
	tar --exclude=./vendor --exclude=./var --exclude=./.git --exclude=./docs --exclude=./composer.lock -cf - . | tar -xf - -C $(LOWEST_DIR)
	$(PHP_LOWEST) sh -c 'composer require sylius/sylius:$(LOWEST_SYLIUS) --no-update --no-interaction \
		&& COMPOSER_NO_BLOCKING=1 composer update --no-interaction --no-progress --no-audit \
		&& $(KEY) && $(DB_SETUP) \
		&& vendor/bin/phpunit --testsuite unit && vendor/bin/phpunit --testsuite functional'

down:
	$(COMPOSE) down --volumes
