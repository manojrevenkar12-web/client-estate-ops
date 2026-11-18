FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libsqlite3-dev unzip \
    && docker-php-ext-install pdo_mysql pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /srv/estate
COPY estate/composer.json ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts
COPY estate/ ./
COPY config/ /srv/config/
RUN composer dump-autoload --no-dev --optimize \
    && sed -ri 's!/var/www/html!/srv/estate/public!g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /srv/estate/public>\n  AllowOverride None\n  Require all granted\n  FallbackResource /index.php\n</Directory>\n' > /etc/apache2/conf-enabled/estate.conf \
    && mkdir -p /srv/var && chown www-data /srv/var
ENV ESTATE_DSN=sqlite:/srv/var/estate.sqlite
USER www-data
EXPOSE 80
