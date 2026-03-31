FROM php:8.2-apache

# Εγκατάσταση system dependencies για GD (image resizing)
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libwebp-dev \
    && rm -rf /var/lib/apt/lists/*

# Εγκατάσταση PHP extensions
RUN docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j$(nproc) \
        mysqli \
        gd \
        fileinfo \
        mbstring \
        intl

# Ενεργοποίηση mod_rewrite για Apache
RUN a2enmod rewrite

# Αντιγραφή κώδικα στον Apache document root
COPY . /var/www/html/

# Σωστά permissions για upload directories
RUN chown -R www-data:www-data /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/assets/product_color_photos \
    && chown -R www-data:www-data /var/www/html/assets/yarn_colors \
    && chmod -R 755 /var/www/html/uploads \
    && chmod -R 755 /var/www/html/assets/product_color_photos \
    && chmod -R 755 /var/www/html/assets/yarn_colors

# Αντιγραφή custom php.ini
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Αντιγραφή Apache config
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 80
