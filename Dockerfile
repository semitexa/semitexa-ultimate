# Minimal PHP + Swoole for Semitexa (project is mounted at runtime)
#
# PCOV is installed but DISABLED by default (zz-pcov.ini). Coverage is opt-in:
#   php -d pcov.enabled=1 vendor/bin/phpunit <paths> --coverage-text
#
# PCOV rather than Xdebug deliberately: Xdebug is known to conflict with Swoole
# coroutines, which is this framework's entire runtime, and PCOV is far cheaper on
# a large suite. Enabled by default it would make every ordinary test run pay for
# coverage nobody asked for.
#
# pcov.directory is the COLLECTION BOUNDARY and it is the setting that matters:
# left empty, PCOV records nothing and a run completes cleanly reporting 0.00% over
# thousands of classes, with no error and no warning. Isolated by measurement -
# with the directory set, coverage works even at the default initial.files=64;
# with it empty, no value of initial.files helps.
#
# pcov.initial.files only sizes PCOV's internal table up front, so a large suite
# does not reallocate while collecting. It has no effect on WHAT is covered.
#
# vendor/ and tests are excluded so the table is not filled with files no coverage
# report is about.
FROM php:8.4-cli-alpine

# Install Composer from official image (multi-stage, no extra dependencies)
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

ARG IMAGICK_VERSION=3.8.1

# bash, on an image whose default shell is BusyBox ash: semitexa/update ships
# its deploy tooling as bash scripts (tools/run-auto-deploy-systemd.sh and its
# installer both declare `#!/usr/bin/env bash`), and the suite that covers them
# runs in this image. Without it those tests fail as `sh: bash: not found`,
# which reads like a broken test rather than a missing package.
# zlib-dev is what makes Swoole compress anything at all. config.m4 detects zlib
# with AC_CHECK_LIB(z, gzgets) — no flag to enable it, and no error when the
# header is missing — and SW_HAVE_COMPRESSION is defined only if zlib, brotli or
# zstd is present. The other two are switched off on the configure line below on
# purpose, so without this package the extension is built with every compression
# path compiled out, and `http_compression` (which the Server constructor turns
# ON by default) is accepted and silently inert.
#
# MEASURED in the framework workspace before it was added: GET / returned 284290
# bytes with no Content-Encoding however the client asked, against 52655 with it.
# Nothing reported a problem, because there was nothing to report — the setting
# was accepted and the code was not there.
#
# openssh-client: `update:packages:bootstrap-remote` runs here (bin/semitexa
# passes commands into this container) and needs ssh and scp; without them it
# stopped at "Missing local prerequisites: ssh, scp" in every installed project.
#
# session.upload_progress off: the server is Swoole (the cli SAPI), which
# parses uploads itself and never reaches PHP's upload-progress hook, and
# Semitexa does not use ext/session. Off anyway, so a project that ever runs
# this image's PHP under another SAPI does not carry that surface.
#
# /etc/passwd writable: the container runs as the host user's uid, which has
# no account here unless it is 1000, and ssh refuses to run for a uid with no
# account. The bootstrap adds one when it is missing. The image has no setuid
# program, so the file grants nothing beyond naming the uid.
RUN apk add --no-cache autoconf g++ make linux-headers openssl-dev zlib-dev git unzip imagemagick-dev imagemagick-webp imagemagick-jpeg imagemagick-heic bash openssh-client \
    && docker-php-ext-install pdo pdo_mysql sockets \
    && pecl install --nobuild swoole \
    && cd "$(pecl config-get temp_dir)/swoole" \
    && phpize && ./configure --enable-openssl --disable-brotli --disable-zstd \
    && make -j$(nproc) && make install \
    && docker-php-ext-enable swoole \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && pecl install imagick-"${IMAGICK_VERSION}" \
    && docker-php-ext-enable imagick \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && printf 'pcov.enabled=0\npcov.directory=/var/www/html\npcov.exclude="~(vendor|/tests/)~"\npcov.initial.files=20000\n' > /usr/local/etc/php/conf.d/zz-pcov.ini \
    && printf 'session.upload_progress.enabled=0\n' > /usr/local/etc/php/conf.d/zz-semitexa-session.ini \
    && chmod 0666 /etc/passwd \
    && addgroup -g 1000 -S semitexa \
    && adduser -u 1000 -S -G semitexa -h /var/www/html semitexa

# pcntl, as a layer of its own so the Swoole build above stays cached: the
# queue workers catch docker stop's SIGTERM with it and finish the job in hand
# (QueueStopSignals) instead of dying in the middle of it.
RUN docker-php-ext-install pcntl

# Node, as a SEPARATE layer on purpose: it lands after the Swoole build above, so
# adding or changing it re-uses that cached layer instead of recompiling Swoole.
#
# It exists for the server/client render parity test in semitexa-ssr.
# semitexa-twig.js renders deferred slots in the browser while PHP Twig renders
# them on the server, and the only honest way to prove the two agree is to execute
# both on the same input. Without node the test SKIPS rather than fails, so a
# release gate would go green having never checked parity at all.
RUN apk add --no-cache nodejs

WORKDIR /var/www/html

USER semitexa

CMD ["php", "server.php"]
