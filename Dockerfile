
FROM php:8.2-apache

# Dependencies for PostgreSQL, image processing, cURL, and multibyte text
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    fonts-dejavu-core \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo pdo_pgsql gd exif curl mbstring \
    && rm -rf /var/lib/apt/lists/*

# Allow phone photos up to 12 MB
RUN printf 'upload_max_filesize=12M\npost_max_size=14M\nmemory_limit=256M\n' \
    > /usr/local/etc/php/conf.d/uploads.ini

# Copy project into Apache's web root
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
