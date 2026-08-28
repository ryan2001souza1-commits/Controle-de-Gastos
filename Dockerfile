# =============================================================================
# Dockerfile — FrankenPHP + PHP 8 + PostgreSQL
# =============================================================================
# Imagem base: FrankenPHP com PHP 8.4 (Debian/Trixie)
# Imagem oficial: https://hub.docker.com/r/dunglas/frankenphp
# =============================================================================

FROM dunglas/frankenphp:1.0-php8.4-trixie

# Versão fixa para reprodutibilidade
ARG FRANKENPHP_VERSION=1.0

# ---------------------------------------------------------------------------
# 1. Instalar extensão PostgreSQL para PHP
# ---------------------------------------------------------------------------
RUN install-php-extensions pdo_pgsql pgsql > /dev/null 2>&1 || true

# ---------------------------------------------------------------------------
# 2. Copiar aplicação
#    Estrutura: /app/public (raiz web), /app/src (lógica PHP)
# ---------------------------------------------------------------------------
COPY . /app

# ---------------------------------------------------------------------------
# 3. Substituir o Caddyfile padrão pelo do projeto
#    FrankenPHP lê a config de /etc/caddy/Caddyfile por padrão.
# ---------------------------------------------------------------------------
COPY Caddyfile /etc/caddy/Caddyfile

# ---------------------------------------------------------------------------
# 4. Configurar PHP para o ambiente de container
# ---------------------------------------------------------------------------
RUN mkdir -p /tmp/sessions && chmod 1777 /tmp/sessions && \
    echo "session.save_path=/tmp/sessions" >> /usr/local/etc/php/conf.d/docker.ini && \
    echo "memory_limit=128M" >> /usr/local/etc/php/conf.d/docker.ini && \
    echo "upload_max_filesize=10M" >> /usr/local/etc/php/conf.d/docker.ini

# ---------------------------------------------------------------------------
# 5. Variáveis de ambiente
# ---------------------------------------------------------------------------
ENV PHP_MEMORY_LIMIT=128M
ENV PHP_MAX_EXECUTION_TIME=60
ENV PHP_UPLOAD_MAX_FILESIZE=10M
ENV PORT=80

# ---------------------------------------------------------------------------
# 6. Ponto de entrada: FrankenPHP com Caddyfile customizado
# ---------------------------------------------------------------------------
WORKDIR /app

EXPOSE 80 443

CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]

