# Minimal PHP + Swoole for Semitexa (project is mounted at runtime)
FROM php:8.4-cli-alpine

# Install Composer from official image (multi-stage, no extra dependencies)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN apk add --no-cache autoconf g++ make linux-headers openssl-dev git unzip \
    && docker-php-ext-install pdo pdo_mysql \
    && pecl install --nobuild swoole \
    && cd "$(pecl config-get temp_dir)/swoole" \
    && phpize && ./configure --enable-openssl --disable-brotli --disable-zstd \
    && make -j$(nproc) && make install \
    && docker-php-ext-enable swoole \
    && pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /var/www/html

CMD ["php", "server.php"]
