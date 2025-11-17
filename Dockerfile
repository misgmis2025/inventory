# Production Dockerfile for Railway using PHP built-in server and MongoDB extension
FROM php:8.2-cli

# System deps and MongoDB extension
RUN apt-get update && apt-get install -y \
    git unzip libssl-dev pkg-config \
 && pecl install mongodb \
 && docker-php-ext-enable mongodb \
 && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy repo
COPY . /var/www/html

# Install Composer and PHP deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --prefer-dist --no-interaction --working-dir=/var/www/html

# Health: print PHP and verify extension during build
RUN php -v && php -m | grep -i mongodb || (echo "mongodb extension missing" && exit 1)

# Expose and run on platform PORT (fallback 8080) from repo root
EXPOSE 8080
CMD ["sh","-lc","php -S 0.0.0.0:${PORT:-8080} -t /var/www/html"]
