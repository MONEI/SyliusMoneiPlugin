.PHONY: install test test-unit test-integration analyse fix check

install:
	composer install

test:
	vendor/bin/phpunit

test-unit:
	vendor/bin/phpunit --testsuite=Unit

test-integration:
	vendor/bin/phpunit --testsuite=Integration

analyse:
	vendor/bin/phpstan analyse

fix:
	vendor/bin/php-cs-fixer fix

check:
	vendor/bin/php-cs-fixer fix --dry-run --diff
	vendor/bin/phpstan analyse
	vendor/bin/phpunit
