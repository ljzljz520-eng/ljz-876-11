#!/bin/sh
set -e

# 等待数据库就绪后执行迁移（新增监考/申诉表对存量库也是安全增量）
echo "Waiting for database..."
ATTEMPTS=0
until php -r "try { new PDO('mysql:host='.getenv('DB_HOST').';port='.(getenv('DB_PORT') ?: '3306').';dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); exit(0); } catch (\Throwable \$e) { exit(1); }" 2>/dev/null
do
  ATTEMPTS=$((ATTEMPTS + 1))
  if [ "$ATTEMPTS" -ge 60 ]; then
    echo "Database not reachable, starting anyway."
    break
  fi
  sleep 2
done

php artisan migrate --force --no-interaction || echo "migrate skipped/failed (fresh init may already contain schema)"

exec php artisan serve --host=0.0.0.0 --port=8080
