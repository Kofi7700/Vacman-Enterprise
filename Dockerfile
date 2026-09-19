# Render has no native PHP runtime, so the app is deployed as a Docker
# web service. See the Render deployment notes at the bottom of README.md.
FROM php:8.2-apache

# pdo_mysql/mysqli need no extra system packages, so this is a plain
# docker-php-ext-install. mod_rewrite isn't on by default; mod_alias
# (used by the RedirectMatch rules below) already is. AllowOverride All
# lets .htaccess actually apply once it's copied in.
RUN docker-php-ext-install pdo pdo_mysql mysqli \
 && a2enmod rewrite \
 && sed -ri -e 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

COPY . /var/www/html/

# Render's default expected port is 10000. Apache listens on 80 by
# default, so remap it at build time.
RUN sed -i 's/80/10000/g' /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf
EXPOSE 10000
