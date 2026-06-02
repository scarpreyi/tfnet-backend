FROM php:8.2-apache

# Enable mod_rewrite and mod_headers
RUN a2enmod rewrite headers

# Install mysqli extension
RUN docker-php-ext-install mysqli

# Copy all project files to web root
COPY . /var/www/html/

# Set Apache document root to /public
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -ri -e 's!/var/www/html!/var/www/html/public!g' \
    /etc/apache2/sites-available/*.conf && \
    sed -ri -e 's!/var/www/!/var/www/html/public!g' \
    /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Create proper directory configuration with correct permissions
RUN echo '<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks MultiViews\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>\n\
<Directory /var/www/html>\n\
    Require all denied\n\
</Directory>' > /etc/apache2/conf-available/tfnet.conf && \
    a2enconf tfnet && \
    a2disconf other-vhosts-access-log

# Set permissions
RUN chown -R www-data:www-data /var/www/html && \
    chmod -R 755 /var/www/html && \
    chmod -R 775 /var/www/html/public

EXPOSE 80
