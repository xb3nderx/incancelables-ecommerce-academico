FROM php:8.2-apache

# Instalar extensiones PHP para MySQL y Resend
RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql curl \
    && rm -rf /var/lib/apt/lists/*

# Habilitar reescritura de URLs
RUN a2enmod rewrite

# Diagnóstico de módulos MPM habilitados
RUN ls -l /etc/apache2/mods-enabled/mpm_*.load \
    && apache2ctl -t

# Configurar public/ como directorio web
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri \
    -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf

# Permitir las reglas de public/.htaccess
RUN printf '<Directory /var/www/html/public>\nAllowOverride All\nRequire all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/incancelables.conf \
    && a2enconf incancelables

# Puerto HTTP de Apache
EXPOSE 80

# Copiar el backend
COPY . /var/www/html/
