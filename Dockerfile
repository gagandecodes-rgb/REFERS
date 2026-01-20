FROM php:8.2-apache

# Install dependencies needed to build pdo_pgsql
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Copy your project into Apache web root
COPY . /var/www/html/

# Permissions (optional but safe)
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
