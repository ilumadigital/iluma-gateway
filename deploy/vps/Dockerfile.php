FROM php:8.3-fpm-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libsodium-dev ca-certificates \
    && docker-php-ext-install -j2 pdo_mysql curl \
    && if ! php -m | grep -Eiq '^sodium$'; then docker-php-ext-install sodium; fi \
    && rm -rf /var/lib/apt/lists/*

COPY deploy/vps/php.ini /usr/local/etc/php/conf.d/zz-iluma.ini
WORKDIR /srv/iluma-gateway/app
COPY . /srv/iluma-gateway/app/
RUN chown -R root:root /srv/iluma-gateway/app \
    && chmod -R a-w /srv/iluma-gateway/app \
    && php -r 'foreach(["pdo_mysql","sodium","curl","json","openssl"] as $e) {if (!extension_loaded($e)) {fwrite(STDERR,"Missing $e\n");exit(1);}}'

# The web and worker PHP runtime cannot mutate application code.
USER www-data
CMD ["php-fpm", "-F"]
