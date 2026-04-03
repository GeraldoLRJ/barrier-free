FROM php:8.2-fpm

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libpq-dev

# Limpar cache do apt
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP necessárias para o Laravel e PostgreSQL
RUN docker-php-ext-install pdo pdo_pgsql pgsql mbstring exif pcntl bcmath gd

# Instalar Xdebug
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Instalar o Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Definir o diretório de trabalho
WORKDIR /var/www

CMD ["php-fpm"]
