# Minimal PHP + Swoole for Semitexa (project is mounted at runtime)
FROM php:8.4-cli-alpine

# Install Composer from official image (multi-stage, no extra dependencies)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ARG IMAGICK_VERSION=3.8.1

RUN apk add --no-cache autoconf g++ make linux-headers openssl-dev git unzip imagemagick-dev \
    && docker-php-ext-install pdo pdo_mysql \
    && pecl install --nobuild swoole \
    && cd "$(pecl config-get temp_dir)/swoole" \
    && phpize && ./configure --enable-openssl --disable-brotli --disable-zstd \
    && make -j$(nproc) && make install \
    && docker-php-ext-enable swoole \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install imagick-"${IMAGICK_VERSION}" \
    && docker-php-ext-enable imagick \
    && addgroup -g 1000 -S semitexa \
    && adduser -u 1000 -S -G semitexa -h /var/www/html semitexa

WORKDIR /var/www/html

USER semitexa

CMD ["php", "server.php"]
