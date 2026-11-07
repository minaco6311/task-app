# Laravel を動かす PHP の環境
#   base … 開発用・本番用で共通
#   prod … 本番用（docker-compose.prod.yml が target: prod で使う）
#   dev  … 開発用・E2E 用（いちばん最後なので、target を書かないとこれになる）
FROM php:8.4-fpm AS base

# Laravel と MySQL に必要な PHP の拡張
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libicu-dev \
    && docker-php-ext-install pdo_mysql zip intl bcmath \
    && rm -rf /var/lib/apt/lists/*

# Composer（PHP のパッケージ管理）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# アップロードの上限（バックアップの読み込みは 5MB まで → PHP 側は少し余裕を持たせる）
COPY php.ini /usr/local/etc/php/conf.d/zz-irodori.ini

WORKDIR /var/www/html

# ----- 本番用 -----
# サーバー（Amazon Linux 2023）では、リポジトリを ec2-user（uid 1000）で git clone する。
# PHP（php-fpm・scheduler・artisan）を同じ uid で動かし、storage/ や bootstrap/cache/ に書き込めるようにする
# （root で動かすと root 所有のログやキャッシュができ、あとで書き込めなくなるため）
FROM base AS prod
ARG APP_UID=1000
ARG APP_GID=1000
# ホーム（composer のキャッシュ・tinker の設定の置き場所）は書き込める /home/www-data にする（標準の /var/www は root 所有）
RUN groupmod -o -g "${APP_GID}" www-data \
    && usermod -o -u "${APP_UID}" -g "${APP_GID}" -d /home/www-data www-data \
    && install -d -o www-data -g www-data -m 700 /home/www-data
USER www-data

# ----- 開発用（Mac の Docker Desktop。今までどおり） -----
FROM base AS dev
