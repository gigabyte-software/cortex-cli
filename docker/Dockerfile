FROM php:8.3-cli-alpine

# Install system dependencies
RUN apk add --no-cache \
    bash \
    git \
    zip \
    unzip \
    curl \
    linux-headers \
    $PHPIZE_DEPS

# Install Xdebug for code coverage
RUN pecl install xdebug-3.3.1 \
    && docker-php-ext-enable xdebug

# Configure Xdebug for coverage (not debugging)
RUN echo "xdebug.mode=coverage" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Configure git to allow mounted volumes
RUN git config --global --add safe.directory /app

# Set working directory
WORKDIR /app

# Keep container running
CMD ["tail", "-f", "/dev/null"]

