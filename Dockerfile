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

# Promote nested app into web root if present
RUN if [ -d /var/www/html/inventory/inventory/inventory ]; then \
      cp -R /var/www/html/inventory/inventory/inventory/* /var/www/html/ ; \
    fi

# Install Composer and PHP deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --prefer-dist --no-interaction --working-dir=/var/www/html

# Health: print PHP and verify extension during build
RUN php -v && php -m | grep -i mongodb || (echo "mongodb extension missing" && exit 1)

# Expose and run on platform PORT (fallback 8080)
# Use router.php so static files work whether the app is nested or promoted to web root
EXPOSE 8080
CMD ["sh","-lc","php -S 0.0.0.0:${PORT:-8080} router.php"]

HEALTHCHECK --interval=20s --timeout=5s --retries=6 CMD curl -fsS http://127.0.0.1:${PORT:-8080}/ok.txt || exit 1
