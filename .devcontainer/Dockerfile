FROM mcr.microsoft.com/devcontainers/php:8.2

# Install additional PHP extensions if needed
RUN apt-get update && apt-get install -y \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-mysql \
    php8.2-curl \
    php8.2-zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
