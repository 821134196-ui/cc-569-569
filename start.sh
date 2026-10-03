#!/usr/bin/env bash
#
# 酒店夜审系统 · 一条命令启动
set -euo pipefail
cd "$(dirname "$0")"

PHP_BIN="$PWD/.local/bin/php"
COMPOSER="$PWD/.local/bin/composer"
PHP_VERSION="8.5.8"

# --- 1. 准备 PHP（无系统 PHP 时下载静态编译版本） ---
if [ ! -x "$PHP_BIN" ]; then
    if command -v php >/dev/null 2>&1; then
        PHP_BIN="$(command -v php)"
    else
        echo "==> 未找到 PHP，下载静态编译的 PHP $PHP_VERSION ..."
        mkdir -p .local/bin
        tmp="$(mktemp -d)"
        curl -fsSL -o "$tmp/php.tar.gz" "https://dl.static-php.dev/static-php-cli/common/php-${PHP_VERSION}-cli-linux-x86_64.tar.gz"
        tar xzf "$tmp/php.tar.gz" -C "$tmp"
        mv "$tmp/php" "$PHP_BIN"
        chmod +x "$PHP_BIN"
        rm -rf "$tmp"
    fi
fi
echo "==> PHP: $("$PHP_BIN" -v | head -1)"

# --- 2. 准备 Composer ---
if [ ! -f "$COMPOSER" ]; then
    echo "==> 下载 Composer ..."
    mkdir -p .local/bin
    curl -fsSL -o "$COMPOSER" "https://getcomposer.org/download/latest-stable/composer.phar"
    chmod +x "$COMPOSER"
fi

# --- 3. 安装依赖 ---
if [ ! -f vendor/autoload.php ]; then
    echo "==> composer install ..."
    "$PHP_BIN" "$COMPOSER" install --no-interaction --prefer-dist
fi
if [ ! -d node_modules ]; then
    echo "==> npm install ..."
    npm install
fi

# --- 4. 环境配置 ---
if [ ! -f .env ]; then
    cp .env.example .env
    "$PHP_BIN" artisan key:generate
fi

# --- 5. 数据库（SQLite）：首次初始化并灌入演示数据 ---
if [ ! -f database/database.sqlite ]; then
    echo "==> 初始化 SQLite 数据库并灌入演示数据 ..."
    touch database/database.sqlite
    "$PHP_BIN" artisan migrate:fresh --seed --force
else
    "$PHP_BIN" artisan migrate --force
fi

# --- 6. 构建前端 ---
if [ ! -f public/build/manifest.json ]; then
    echo "==> 构建前端资源 ..."
    npm run build
fi

# --- 7. 启动 ---
PORT="${PORT:-8000}"
echo ""
echo "==> 酒店夜审系统已启动: http://127.0.0.1:${PORT}"
echo "    演示账号（页面右上角切换）：夜审员甲 / 夜审员乙 / 前台小王 / 财务小李"
echo "    端到端验证脚本: ./scripts/demo.sh"
echo ""
exec "$PHP_BIN" artisan serve --host=0.0.0.0 --port="$PORT"
