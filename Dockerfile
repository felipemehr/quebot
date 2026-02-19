FROM php:8.2-apache

# Install PostgreSQL driver + other dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy application to web root
COPY . /var/www/html/

# Set permissions
RUN chown -R www-data:www-data /var/www/html

# Railway sets PORT env var - configure Apache to use it
CMD sed -i "s/80/$PORT/g" /etc/apache2/sites-available/000-default.conf /etc/apache2/ports.conf && apache2-foreground
