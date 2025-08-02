FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --update --no-cache \
    ${PHPIZE_DEPS} \
    curl \
    freetype-dev \
    gettext-dev \
    git \
    icu-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    libxml2-dev \
    libzip-dev \
    linux-headers \
    oniguruma-dev \
    unzip \
    zip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        intl \
        zip \
        opcache \
        gettext \
    # Install PECL extensions
    && pecl install redis \
    # && pecl install xdebug \
    && docker-php-ext-enable redis \
    # && docker-php-ext-enable xdebug \
    # Clean up build dependencies to reduce image size
    && apk del ${PHPIZE_DEPS} \
    && rm -rf /tmp/pear

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# https://getcomposer.org/doc/03-cli.md#composer-allow-superuser
ENV COMPOSER_ALLOW_SUPERUSER=1

# Copy PHP configuration
COPY ./docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/public

# Copy composer files first for better layer caching
COPY composer.* ./

# Install the dependencies
RUN composer install --no-dev --no-scripts --no-autoloader --no-progress --no-interaction

# Copy the rest of the application
COPY . ./

# Generate optimized autoloader
RUN composer dump-autoload --classmap-authoritative --no-dev

EXPOSE 9000

CMD ["php-fpm"]
