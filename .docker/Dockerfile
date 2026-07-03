ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-cli

# mlocati's installer builds the needed extensions across all target PHP versions.
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions intl gd mysqli pdo_mysql zip bcmath exif pcov

# Measure coverage against the mounted module source only.
RUN echo 'pcov.directory=/module/src' > /usr/local/etc/php/conf.d/99-pcov.ini

# The TinyMCE build task loads a translation catalogue per i18n locale (hundreds),
# so PHP's 128M default is insufficient; raise it container-wide so every invocation
# (task, CI running vendor/bin/phpunit directly, and manual runs) is covered.
RUN echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/99-memory.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY app/composer.json /app/composer.json

# SilverStripe requires Page and PageController classes in the project. These are
# generated here (not committed) to avoid duplicate class detection when the module
# is symlinked into vendor/. SilverStripe discovers modules by looking for _config/;
# without a project-root _config/, /app/src/ is not scanned and Page/PageController
# stay invisible to the class manifest (which breaks temp-database creation in tests).
RUN mkdir -p /app/src /app/_config \
    && printf '<?php\n\nuse SilverStripe\\CMS\\Model\\SiteTree;\n\nclass Page extends SiteTree\n{\n}\n' > /app/src/Page.php \
    && printf '<?php\n\nuse SilverStripe\\CMS\\Controllers\\ContentController;\n\nclass PageController extends ContentController\n{\n}\n' > /app/src/PageController.php

COPY app/_config/ /app/_config/
COPY app/phpunit.xml.dist /app/phpunit.xml.dist
COPY app/phpstan.neon.dist /app/phpstan.neon.dist
COPY app/phpstan-php85.neon.dist /app/phpstan-php85.neon.dist
COPY app/rector.php /app/rector.php

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
