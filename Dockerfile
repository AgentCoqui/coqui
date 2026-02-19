###############################################################################
# Coqui — PHP 8.4 CLI Development Image
#
# Terminal AI agent — PHP 8.4 CLI + extensions + Composer + Xdebug/pcov
# (both disabled by default, enabled via compose overlays)
#
# Usage:
#   docker compose run --rm coqui            # interactive REPL
#   docker compose run --rm coqui --help     # CLI help
###############################################################################

FROM php:8.4-cli

LABEL maintainer="Coqui Bot <hello@coqui.bot>"
LABEL description="Coqui development image — PHP 8.4 CLI + Composer + Xdebug + pcov"

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

# Xdebug + pcov — installed but NOT enabled by default
# Xdebug is enabled via compose.dev.yaml (mounting xdebug.ini)
# pcov is enabled via compose.test.yaml (mounting test.ini)
RUN pecl install xdebug pcov \
    && true

# Create xdebug output directory
RUN mkdir -p /tmp/xdebug \
    && chmod 1777 /tmp/xdebug

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

# Ensure xdebug output is writable
RUN chown -R coqui:coqui /tmp/xdebug

# -----------------------------------------------------------------------------
# Utility scripts
# -----------------------------------------------------------------------------

# Clear xdebug profiler output
RUN printf '#!/bin/bash\nrm -f /tmp/xdebug/cachegrind.out.* 2>/dev/null\necho "Xdebug profiler files cleared."\n' \
    > /usr/local/bin/clear-xdebug \
    && chmod +x /usr/local/bin/clear-xdebug

# -----------------------------------------------------------------------------
# PHP configuration
# -----------------------------------------------------------------------------
COPY conf.d/coqui.ini /usr/local/etc/php/conf.d/coqui.ini

# -----------------------------------------------------------------------------
# Working directory
# -----------------------------------------------------------------------------
WORKDIR /app

# Pre-create workspace so Docker named volumes inherit correct ownership.
# Docker copies image directory ownership into named volumes on first mount.
RUN mkdir -p /app/.workspace && chown coqui:coqui /app/.workspace

# Run as non-root user
USER coqui

ENTRYPOINT ["php", "bin/coqui"]
CMD []
