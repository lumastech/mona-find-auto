# syntax=docker/dockerfile:1

###############################################################################
# MonaFindAuto — PHP application image
#
# Used by the `app`, `horizon` and `scheduler` services in compose.yaml. The
# same image runs all three; only the command differs.
###############################################################################

FROM php:8.4-cli-bookworm

ARG UID=1000
ARG GID=1000

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_MEMORY_LIMIT=-1

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libzip-dev \
        libicu-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libwebp-dev \
        libonig-dev \
        default-mysql-client \
        # Media Library needs these for image conversions and PDF/video thumbnails.
        jpegoptim optipng pngquant gifsicle webp \
        ffmpeg \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_mysql \
        zip

# Redis is reached through predis (see REDIS_CLIENT), so no phpredis build is
# needed; pcntl above is what Horizon requires.

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Run as the host user so files written from inside the container stay editable.
RUN groupadd --force -g ${GID} monafind \
    && useradd -ms /bin/bash --no-user-group -g ${GID} -u ${UID} monafind

COPY docker/app/php.ini /usr/local/etc/php/conf.d/99-monafind.ini
COPY docker/app/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /var/www/html

USER monafind

EXPOSE 8000

ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
