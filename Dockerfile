###############################################################################
# Coqui — PHP 8.4 CLI Image
#
# Terminal AI agent — PHP 8.4 CLI + extensions + Composer
#
# Usage:
#   docker compose run --rm coqui            # interactive REPL
#   docker compose run --rm coqui --help     # CLI help
###############################################################################

FROM php:8.4-cli

LABEL maintainer="Coqui Bot <carmelo@coquibot.ai>"
LABEL description="Coqui — PHP 8.4 CLI + Composer"
LABEL org.opencontainers.image.source="https://github.com/AgentCoqui/coqui"

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
    && rm -rf /var/lib/apt/lists/*

# -----------------------------------------------------------------------------
# PHP extensions
# -----------------------------------------------------------------------------
# Most extensions are already built into php:8.4-cli:
#   curl, mbstring, xml, pdo_sqlite, readline, opcache, json, openssl
# Only zip and pcntl need to be installed separately.
RUN docker-php-ext-install \
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
ARG COQUI_UID=1000
ARG COQUI_GID=1000

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

# Regenerate the classmap now that source files are present.
# The composer install above runs before COPY to cache the vendor layer, but the
# --classmap-authoritative classmap must be rebuilt against the actual src/ tree.
RUN composer dump-autoload \
    --classmap-authoritative \
    --optimize \
    --no-interaction

ENTRYPOINT ["php", "bin/coqui"]
CMD []
