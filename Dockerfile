# Railway-ready PHP + Apache image with MongoDB driver
FROM php:8.2-apache

# System deps and MongoDB extension
RUN apt-get update && apt-get install -y \
    libssl-dev pkg-config git unzip \
 && pecl install mongodb \
 && docker-php-ext-enable mongodb \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Set Apache DocumentRoot to /var/www/html/inventory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/inventory
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

# Copy app
COPY . /var/www/html

# Ensure PHP session directory exists and is writable
RUN mkdir -p /var/www/html/tmp_sessions \
    && chown -R www-data:www-data /var/www/html/tmp_sessions \
    && chmod -R 0777 /var/www/html/tmp_sessions

# Install Composer and PHP deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev --prefer-dist --no-interaction

# Health: show PHP and extension
RUN php -v && php -m | grep -i mongodb || true

EXPOSE 80
CMD ["apache2-foreground"]
