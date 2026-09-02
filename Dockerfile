# =====================================================================
#  LOCAL SERVICE BOOKING & MANAGEMENT SYSTEM
#  BCSP-064 -- Bachelor of Computer Applications, IGNOU
# ---------------------------------------------------------------------
#  Container image for deployment (Coolify, or any Docker host).
#
#  The local development environment remains XAMPP, exactly as named on
#  the approved project proforma. This image runs the SAME stack --
#  Apache + PHP + MySQL -- so nothing about the application changes;
#  only how it is served does.
# =====================================================================

FROM php:8.2-apache

LABEL org.opencontainers.image.title="Local Service Booking & Management System" \
      org.opencontainers.image.description="BCSP-064 BCA project, IGNOU" \
      org.opencontainers.image.authors="Gagan Sahay <2400652732>"

# ---------------------------------------------------------------------
# PHP extensions
# ---------------------------------------------------------------------
# pdo_mysql is the only database driver the application uses.
# gd and mbstring back the image-upload validation and Unicode handling.
# ---------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
        libfreetype6-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libwebp-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql gd \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# ---------------------------------------------------------------------
# Apache modules
# ---------------------------------------------------------------------
RUN a2enmod rewrite headers

# ---------------------------------------------------------------------
# Production PHP settings
# ---------------------------------------------------------------------
# display_errors is off here as well as in config.php -- two independent
# switches, so a mistake in one does not expose stack traces.
# ---------------------------------------------------------------------
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && { \
        echo 'display_errors = Off'; \
        echo 'log_errors = On'; \
        echo 'error_log = /dev/stderr'; \
        echo 'expose_php = Off'; \
        echo 'upload_max_filesize = 4M'; \
        echo 'post_max_size = 8M'; \
        echo 'session.cookie_httponly = 1'; \
        echo 'session.cookie_samesite = Lax'; \
        echo 'session.use_strict_mode = 1'; \
        echo 'date.timezone = Asia/Kolkata'; \
    } > "$PHP_INI_DIR/conf.d/zz-lsbms.ini"

# ---------------------------------------------------------------------
# Directory protection
# ---------------------------------------------------------------------
# Mirrors the local httpd-vhosts.conf rules: the PHP libraries and the
# SQL scripts are never meant to be requested over HTTP, and nothing in
# the uploads folder may ever execute as a script.
# ---------------------------------------------------------------------
RUN { \
        echo '<Directory /var/www/html/config>'; \
        echo '    Require all denied'; \
        echo '</Directory>'; \
        echo '<Directory /var/www/html/includes>'; \
        echo '    Require all denied'; \
        echo '</Directory>'; \
        echo '<Directory /var/www/html/database>'; \
        echo '    Require all denied'; \
        echo '</Directory>'; \
        echo '<Directory /var/www/html/assets/uploads>'; \
        echo '    php_flag engine off'; \
        echo '    Options -ExecCGI -Indexes'; \
        echo '    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8'; \
        echo '</Directory>'; \
        echo '<Directory /var/www/html>'; \
        echo '    Options -Indexes +FollowSymLinks'; \
        echo '    AllowOverride All'; \
        echo '    Require all granted'; \
        echo '</Directory>'; \
        echo 'ServerTokens Prod'; \
        echo 'ServerSignature Off'; \
    } > /etc/apache2/conf-available/lsbms.conf \
    && a2enconf lsbms

# ---------------------------------------------------------------------
# Application code
# ---------------------------------------------------------------------
WORKDIR /var/www/html
COPY . /var/www/html/

# Uploaded files are written at runtime, so this one directory needs to
# be writable by the web server user. Everything else stays read-only to
# Apache, which limits the damage any single bug can do.
RUN mkdir -p /var/www/html/assets/uploads \
    && chown -R www-data:www-data /var/www/html/assets/uploads \
    && chmod 755 /var/www/html/assets/uploads \
    && chmod +x /var/www/html/docker/entrypoint.sh

EXPOSE 80

# ---------------------------------------------------------------------
# Health check
# ---------------------------------------------------------------------
# The status line is printed on failure rather than suppressed. An
# earlier version used @file_get_contents(), which hid the reason the
# probe failed and left `docker inspect` reporting an empty output for
# every one of 132 consecutive failures -- true, but useless.
# ---------------------------------------------------------------------
HEALTHCHECK --interval=30s --timeout=10s --start-period=45s --retries=3 \
    CMD php -r '$c = @file_get_contents("http://127.0.0.1/index.php"); \
        if ($c === false) { echo $http_response_header[0] ?? "no response", "\n"; exit(1); } \
        exit(0);'

# The entrypoint seeds an empty database before starting Apache.
ENTRYPOINT ["/var/www/html/docker/entrypoint.sh"]
