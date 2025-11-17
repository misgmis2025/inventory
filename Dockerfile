# Railway-ready PHP + Apache image with MongoDB driver
FROM php:8.2-apache

# System deps and MongoDB extension
RUN apt-get update && apt-get install -y \
    libssl-dev pkg-config git unzip \
 && pecl install mongodb \
 && docker-php-ext-enable mongodb \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Serve directly from web root
ENV APACHE_DOCUMENT_ROOT=/var/www/html
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Ensure DirectoryIndex and AllowOverride (expand APACHE_DOCUMENT_ROOT at build time)
RUN printf '%s\n' \
  "<Directory ${APACHE_DOCUMENT_ROOT}>" \
  "  DirectoryIndex index.php index.html" \
  "  AllowOverride All" \
  "  Require all granted" \
  "</Directory>" \
  > /etc/apache2/conf-available/dirindex.conf \
  && a2enconf dirindex

WORKDIR /var/www/html

# Copy repo
COPY . /var/www/html

# Promote nested app (inventory/inventory/inventory) into web root so index.php and assets are directly served
RUN if [ -d /var/www/html/inventory/inventory/inventory ]; then \
      cp -r /var/www/html/inventory/inventory/inventory/* /var/www/html/ ; \
    fi

# Ensure PHP session directory exists and is writable
RUN mkdir -p /var/www/html/tmp_sessions \
    && chown -R www-data:www-data /var/www/html/tmp_sessions \
    && chmod -R 0777 /var/www/html/tmp_sessions

# Install Composer and PHP deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
# Run composer where composer.json lives: /var/www/html/composer.json (repo root)
RUN composer install --no-dev --prefer-dist --no-interaction --working-dir=/var/www/html

# Health: show PHP and extension
RUN php -v && php -m | grep -i mongodb || true

# Simple runtime using PHP built-in server on port 80 (no PORT var required)
RUN printf '%s\n' \
  '#!/bin/sh' \
  'set -e' \
  'echo "Starting PHP built-in server on 0.0.0.0:80"' \
  'exec php -S 0.0.0.0:80 -t /var/www/html' \
  > /usr/local/bin/start-php.sh \
  && chmod +x /usr/local/bin/start-php.sh

EXPOSE 80
CMD ["/usr/local/bin/start-php.sh"]
