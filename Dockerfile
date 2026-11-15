# ── Production Dockerfile ──────────────────────────────────────────────────────
# Base image: PHP 8.3 with Apache
FROM php:8.3-apache

# Install PostgreSQL client libs and build PDO + pdo_pgsql extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Enable Apache mod_rewrite (needed for clean URLs / .htaccess routing)
RUN a2enmod rewrite

# Allow .htaccess overrides in the document root
RUN sed -i 's|AllowOverride None|AllowOverride All|g' /etc/apache2/apache2.conf

# Copy all project files into the web root
COPY . /var/www/html/

# Set correct ownership so Apache can read the files
RUN chown -R www-data:www-data /var/www/html

# Render injects $PORT at runtime; default to 80 for local/dev runs.
# Apache must listen on that port — we write the Listen directive dynamically
# and pass the same value to ServerName to suppress the FQDN warning.
ENV APACHE_PORT=${PORT:-80}

# Entrypoint: update Apache's port at container start, then hand off to Apache
CMD ["/bin/sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-80}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-80}>/\" /etc/apache2/sites-enabled/000-default.conf && apache2-foreground"]

EXPOSE ${PORT:-80}
