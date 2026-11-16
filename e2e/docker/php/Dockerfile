# The e2e store: the Sylius test application (with the refund plugin) and this plugin, in prod mode.
# Build context: the repo root; e2e/docker/php/Dockerfile.dockerignore keeps secrets and local builds out.
ARG PHP_VERSION=8.4

FROM ghcr.io/sylius/sylius-php:${PHP_VERSION}-alpine AS vendor
ARG SYLIUS_VERSION=^2.3.2
ENV PHP_DATE_TIMEZONE=UTC
WORKDIR /app
COPY composer.json ./
COPY src src
COPY config config
COPY templates templates
COPY translations translations
COPY public public
COPY tests/TestApplication tests/TestApplication
RUN --mount=type=cache,target=/root/.composer/cache \
    composer require "sylius/sylius:${SYLIUS_VERSION}" --no-update --no-interaction \
    && composer update --no-interaction --no-progress --no-audit --prefer-dist
COPY e2e/docker/php/config.yaml /app/e2e/config.yaml
ENV APP_ENV=prod \
    APP_DEBUG=0 \
    SYLIUS_TEST_APP_CONFIGS_TO_IMPORT="@MoneiSyliusPlugin/tests/TestApplication/config/config.yaml;/app/e2e/config.yaml"
# Explicit target: the default resolves to /app/public, the plugin's own public dir.
RUN vendor/bin/console assets:install /app/vendor/sylius/test-application/public --no-interaction

FROM node:24-bookworm-slim AS assets
WORKDIR /app
COPY --from=vendor /app /app
# The test application's webpack config adds a plugin entry point; this plugin ships no assets.
RUN mkdir -p assets/shop assets/admin && touch assets/shop/entrypoint.js assets/admin/entrypoint.js
WORKDIR /app/vendor/sylius/test-application
RUN yarn install --non-interactive && yarn build:prod

FROM vendor AS php
COPY --from=assets /app/vendor/sylius/test-application/public/build /app/vendor/sylius/test-application/public/build
COPY e2e/docker/php/zz-e2e.conf /usr/local/etc/php-fpm.d/zz-e2e.conf
RUN vendor/bin/console cache:warmup --no-interaction
# Rootless Docker: root in the container is the host user, and the cache above belongs to root.
CMD ["php-fpm", "--allow-to-run-as-root"]

FROM nginx:1.31.8-alpine AS nginx
COPY e2e/docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY --from=php /app/vendor/sylius/test-application/public /app/vendor/sylius/test-application/public
