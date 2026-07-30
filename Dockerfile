FROM php:8.3-apache

# pdo_mysql is what api.php uses; opcache makes repeated requests much faster
RUN docker-php-ext-install pdo_mysql opcache

RUN a2enmod rewrite headers

# This app stores base64 images and video inside the DB, so the defaults
# (2M upload / 8M post / 128M memory) are far too small.
RUN printf '%s\n' \
    'upload_max_filesize = 64M' \
    'post_max_size = 64M' \
    'memory_limit = 512M' \
    'max_execution_time = 120' \
    'max_input_vars = 5000' \
    > /usr/local/etc/php/conf.d/zz-app.ini

# Needed for .htaccess (URL rewriting + Authorization header passthrough)
RUN printf '%s\n' \
    '<Directory /var/www/html>' \
    '    AllowOverride All' \
    '    Require all granted' \
    '</Directory>' \
    > /etc/apache2/conf-available/app.conf \
    && a2enconf app

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
