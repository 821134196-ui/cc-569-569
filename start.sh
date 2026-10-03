#!/usr/bin/env bash
#
# 酒店夜审系统 · 一条命令启动
#
#   ./start.sh            启动（首次自动安装依赖、建库、播种演示数据）
#   ./start.sh --fresh    重置数据库并重新播种演示数据后启动
#   PORT=8080 ./start.sh  指定端口（默认 8000）
#
set -euo pipefail
cd "$(dirname "$0")"
ROOT="$PWD"

echo "==> [1/5] 准备 PHP 运行时"
if command -v php >/dev/null 2>&1 && php -r 'exit(extension_loaded("pdo_sqlite") ? 0 : 1);' 2>/dev/null; then
  PHP="php"
elif [ -x "$ROOT/.runtime/php" ]; then
  PHP="$ROOT/.runtime/php"
else
  echo "    未检测到带 sqlite 的 PHP，下载静态 PHP 运行时（约 13MB）..."
  mkdir -p "$ROOT/.runtime"
  curl -fsSL -o "$ROOT/.runtime/php.tar.gz" https://dl.static-php.dev/static-php-cli/common/php-8.5.8-cli-linux-x86_64.tar.gz
  tar xzf "$ROOT/.runtime/php.tar.gz" -C "$ROOT/.runtime"
  rm -f "$ROOT/.runtime/php.tar.gz"
  PHP="$ROOT/.runtime/php"
fi
echo "    使用 $("$PHP" -v | head -1)"

echo "==> [2/5] 安装后端依赖（Composer）"
if [ ! -f "$ROOT/server/.env" ]; then
  cp "$ROOT/server/.env.example" "$ROOT/server/.env"
fi
if [ ! -d "$ROOT/server/vendor" ]; then
  if [ ! -f "$ROOT/.runtime/composer" ]; then
    curl -fsSL -o "$ROOT/.runtime/composer" https://getcomposer.org/download/latest-stable/composer.phar
    chmod +x "$ROOT/.runtime/composer"
  fi
  (cd "$ROOT/server" && COMPOSER_HOME="$ROOT/.runtime/composer-home" "$PHP" "$ROOT/.runtime/composer" install --no-interaction --quiet)
fi
if ! grep -q '^APP_KEY=base64:.' "$ROOT/server/.env"; then
  (cd "$ROOT/server" && "$PHP" artisan key:generate --quiet)
fi

echo "==> [3/5] 构建前端（React）"
if [ ! -f "$ROOT/server/spa/index.html" ]; then
  (cd "$ROOT/client" && npm install --no-audit --no-fund --silent && npm run build --silent)
else
  echo "    已存在构建产物，跳过（重新构建：cd client && npm run build）"
fi

echo "==> [4/5] 准备数据库（SQLite）"
cd "$ROOT/server"
touch database/database.sqlite
if [ "${1:-}" = "--fresh" ]; then
  "$PHP" artisan migrate:fresh --seed --quiet
  echo "    已重置并播种演示数据"
elif [ -z "$("$PHP" artisan tinker --execute='echo \App\Models\BusinessDay::count();' 2>/dev/null)" ]; then
  "$PHP" artisan migrate:fresh --seed --quiet
  echo "    已建库并播种演示数据"
else
  "$PHP" artisan migrate --quiet
  echo "    沿用现有数据（如需重置：./start.sh --fresh）"
fi

PORT="${PORT:-8000}"
WORKERS="${WORKERS:-4}"
echo "==> [5/5] 启动服务"
echo ""
echo "    夜审工作台  →  http://127.0.0.1:${PORT}/app"
echo "    API         →  http://127.0.0.1:${PORT}/api/audit/current"
echo ""
exec env PHP_CLI_SERVER_WORKERS="$WORKERS" "$PHP" artisan serve --host=127.0.0.1 --port="$PORT" --no-reload
