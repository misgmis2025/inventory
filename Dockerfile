# Railway-ready PHP + Apache image with MongoDB driver
FROM php:8.2-apache

# System deps and MongoDB extension
RUN apt-get update && apt-get install -y \
    libssl-dev pkg-config git unzip \
 && pecl install mongodb \
 && docker-php-ext-enable mongodb \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Set Apache DocumentRoot to nested app: /var/www/html/inventory/inventory/inventory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/inventory/inventory/inventory
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# Copy app
COPY . /var/www/html

# Ensure PHP session directory exists and is writable (index.php uses ../tmp_sessions from app dir)
RUN mkdir -p /var/www/html/inventory/inventory/tmp_sessions \
    && chown -R www-data:www-data /var/www/html/inventory/inventory/tmp_sessions \
    && chmod -R 0777 /var/www/html/inventory/inventory/tmp_sessions

# Install Composer and PHP deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Run composer where composer.json lives: /var/www/html/composer.json (repo root)
RUN composer install --no-dev --prefer-dist --no-interaction --working-dir=/var/www/html

# Health: show PHP and extension
RUN php -v && php -m | grep -i mongodb || true

# Silence Apache ServerName warning
RUN echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf \
    && a2enconf servername

EXPOSE 80
CMD ["apache2-foreground"]
