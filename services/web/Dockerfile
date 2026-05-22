FROM php:8.2-apache

# Install dependencies, SQLite, and system tools
RUN apt-get update && apt-get install -y \
    sqlite3 \
    libsqlite3-dev \
    curl \
    ca-certificates \
    iproute2 \
    iputils-ping \
    cron \
    && rm -rf /var/lib/apt/lists/*

# Install Docker CLI to communicate with the host's Docker socket
RUN curl -fsSL https://get.docker.com -o get-docker.sh \
    && sh get-docker.sh \
    && rm get-docker.sh

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Enable Apache rewrite module
RUN a2enmod rewrite

# Configure Apache Document Root to point to public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Set working directory
WORKDIR /var/www/html

# Copy application source files into the container
COPY . /var/www/html

# Install cron job that syncs DHCP leases into DNS every minute
COPY lease-sync-cron /etc/cron.d/lease-sync
RUN chmod 0644 /etc/cron.d/lease-sync

# Run database/config initialization script on boot, start cron, then start Apache
CMD ["sh", "-c", "chmod 666 /var/run/docker.sock 2>/dev/null; cron; php /var/www/html/init.php && apache2-foreground"]
