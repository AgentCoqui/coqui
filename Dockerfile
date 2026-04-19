###############################################################################
# Coqui — PHP 8.4 CLI Image
#
# Personal operating system — PHP 8.4 CLI + extensions + Composer
#
# Usage:
#   docker compose run --rm coqui            # interactive REPL
#   docker compose run --rm coqui --help     # CLI help
###############################################################################

FROM php:8.4-cli AS build

ARG COQUI_VERSION=dev
ARG COQUI_UID=1000
ARG COQUI_GID=1000

# Prevent interactive prompts during package installation
ENV DEBIAN_FRONTEND=noninteractive

# -----------------------------------------------------------------------------
# System packages
# -----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    # Development essentials
    git \
    curl \
    wget \
    unzip \
    zip \
    less \
    jq \
    # Build dependencies for PHP extensions
    autoconf \
    gcc \
    g++ \
    make \
    pkg-config \
    # Extension dependencies
    libsqlite3-dev \
    libxml2-dev \
    libcurl4-openssl-dev \
    libzip-dev \
    libonig-dev \
    libreadline-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# PHP extensions
# -----------------------------------------------------------------------------
# Most extensions are already built into php:8.4-cli:
#   curl, mbstring, xml, pdo_sqlite, readline, opcache, json, openssl
# Only gd, zip, and pcntl need to be installed separately.
RUN docker-php-ext-configure gd --with-freetype --with-jpeg

RUN docker-php-ext-install \
    gd \
    zip \
    pcntl

# -----------------------------------------------------------------------------
# Composer
# -----------------------------------------------------------------------------
RUN curl -fsSL https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin \
        --filename=composer \
    && composer --version

# -----------------------------------------------------------------------------
# Create non-root coqui user
# -----------------------------------------------------------------------------
RUN groupadd -g ${COQUI_GID} coqui 2>/dev/null || true \
    && useradd -m -u ${COQUI_UID} -g ${COQUI_GID} -s /bin/bash coqui 2>/dev/null || true

# Composer cache directory
ENV COMPOSER_HOME=/home/coqui/.composer
RUN mkdir -p /home/coqui/.composer && chown -R coqui:coqui /home/coqui/.composer

# -----------------------------------------------------------------------------
# PHP configuration
# -----------------------------------------------------------------------------
COPY conf.d/coqui.ini /usr/local/etc/php/conf.d/coqui.ini

# -----------------------------------------------------------------------------
# Working directory
# -----------------------------------------------------------------------------
WORKDIR /app

# Pre-create workspace and fix /app ownership as root before switching users.
# WORKDIR and COPY default to root:root — the coqui user needs write access
# to create /app/vendor during composer install.
RUN mkdir -p /app/workspace \
    && chown -R coqui:coqui /app

# -----------------------------------------------------------------------------
# Application source (production builds)
# For local development, compose.yaml bind-mounts .:/app which overrides this.
# For GHCR / production images, the source is baked in.
#
# Layer caching: copy dependency manifests first so Composer only re-runs
# when dependencies change, not on every source code change.
# -----------------------------------------------------------------------------
COPY --chown=coqui:coqui composer.json composer.lock /app/

USER coqui

RUN composer install \
    --no-dev \
    --classmap-authoritative \
    --prefer-dist \
    --no-interaction \
    --no-progress \
    --optimize-autoloader

COPY --chown=coqui:coqui . /app

RUN printf '%s\n' "${COQUI_VERSION}" > /app/config/version.txt

# Regenerate the classmap now that source files are present.
# The composer install above runs before COPY to cache the vendor layer, but the
# --classmap-authoritative classmap must be rebuilt against the actual src/ tree.
RUN composer dump-autoload \
    --classmap-authoritative \
    --optimize \
    --no-interaction

FROM php:8.4-cli AS runtime

ARG COQUI_VERSION=dev
ARG COQUI_UID=1000
ARG COQUI_GID=1000

LABEL maintainer="Coqui Bot <carmelo@coquibot.ai>"
LABEL description="Coqui — PHP 8.4 CLI + Composer"
LABEL org.opencontainers.image.source="https://github.com/AgentCoqui/coqui"
LABEL org.opencontainers.image.version="${COQUI_VERSION}"

ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_HOME=/home/coqui/.composer

# -----------------------------------------------------------------------------
# Runtime packages only
# -----------------------------------------------------------------------------
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    wget \
    unzip \
    zip \
    less \
    jq \
    libfreetype6 \
    libjpeg62-turbo \
    libpng16-16t64 \
    libzip5 \
    && apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=build /usr/local/lib/php/extensions/ /usr/local/lib/php/extensions/
COPY --from=build /usr/local/etc/php/conf.d/ /usr/local/etc/php/conf.d/
COPY --from=build /usr/local/bin/composer /usr/local/bin/composer

RUN groupadd -g ${COQUI_GID} coqui 2>/dev/null || true \
    && useradd -m -u ${COQUI_UID} -g ${COQUI_GID} -s /bin/bash coqui 2>/dev/null || true

WORKDIR /app

RUN mkdir -p /app/workspace /home/coqui/.composer \
    && chown -R coqui:coqui /app /home/coqui/.composer

COPY --from=build --chown=coqui:coqui /app /app

USER coqui

ENTRYPOINT ["php", "bin/coqui"]
CMD []
