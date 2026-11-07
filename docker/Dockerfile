# Ubuntu-based image for the Sheets Sync app (PHP-FPM + nginx + queue worker + scheduler).
FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive \
    TZ=UTC \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      software-properties-common ca-certificates curl gnupg unzip git \
      nginx supervisor tzdata mysql-client \
 && add-apt-repository -y ppa:ondrej/php \
 && apt-get update \
 && apt-get install -y --no-install-recommends \
      php8.4-fpm php8.4-cli php8.4-mysql php8.4-sqlite3 php8.4-mbstring php8.4-xml \
      php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath php8.4-gd \
 && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
 && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/supervisor/app.conf /etc/supervisor/conf.d/app.conf
COPY docker/php.ini /etc/php/8.4/fpm/conf.d/99-app.ini
COPY docker/php.ini /etc/php/8.4/cli/conf.d/99-app.ini
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh

RUN sed -i 's|^listen = .*|listen = 127.0.0.1:9000|' /etc/php/8.4/fpm/pool.d/www.conf \
 && sed -i 's|^;clear_env = no|clear_env = no|' /etc/php/8.4/fpm/pool.d/www.conf \
 && mkdir -p /run/php \
 && chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html
EXPOSE 80

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/supervisord.conf"]
