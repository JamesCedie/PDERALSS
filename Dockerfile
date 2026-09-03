FROM php:8.2-apache

# Install the Postgres PDO driver
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

# Copy all project files into Apache's web root
COPY . /var/www/html/

# Make sure Apache can read everything
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
