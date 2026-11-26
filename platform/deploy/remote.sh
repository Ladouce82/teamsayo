#!/usr/bin/env bash
# Runs on the cPanel server over SSH. Usage: remote.sh <env> <sha>
# Zero-downtime release layout: ~/teamsayo/<env>/{releases/<sha>,shared/{.env,storage},current}
set -euo pipefail
ENV="$1"; SHA="$2"
ROOT="$HOME/teamsayo/$ENV"; REL="$ROOT/releases/$SHA"; SHARED="$ROOT/shared"
PHP="${PHP_BIN:-}"
for c in "$PHP" /opt/cpanel/ea-php84/root/usr/bin/php /opt/cpanel/ea-php83/root/usr/bin/php /usr/local/bin/ea-php84 /usr/local/bin/ea-php83 php; do
  [ -n "$c" ] && command -v "$c" >/dev/null 2>&1 && "$c" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' && PHP="$c" && break
done
"$PHP" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || { echo "PHP 8.3+ not found. Select PHP 8.3 in cPanel > MultiPHP Manager or set the PHP_BIN variable."; exit 1; }
echo "Using $("$PHP" -v | head -1)"

mkdir -p "$SHARED/storage"/{app/public,app/backups,framework/cache/data,framework/sessions,framework/views,logs}
chmod 700 "$SHARED" "$SHARED/storage/app/backups"
# APP_KEY is generated once on the server and kept (so encrypted data and sessions survive deploys).
if [ ! -s "$SHARED/app_key" ]; then echo "base64:$(head -c 32 /dev/urandom | base64)" > "$SHARED/app_key"; chmod 600 "$SHARED/app_key"; fi
{ cat "$REL/.env.deploy"; echo "APP_KEY=$(cat "$SHARED/app_key")"; } > "$SHARED/.env"
chmod 600 "$SHARED/.env"; rm -f "$REL/.env.deploy"

rm -rf "$REL/storage"; ln -sfn "$SHARED/storage" "$REL/storage"; ln -sfn "$SHARED/.env" "$REL/.env"
cd "$REL"
"$PHP" artisan down --retry=15 >/dev/null 2>&1 || true
"$PHP" artisan migrate --force
if [ "$ENV" = "staging" ]; then "$PHP" artisan db:seed --class=StagingSeeder --force; fi
"$PHP" artisan optimize
"$PHP" artisan teamsayo:paypal-setup || echo "PayPal setup skipped/failed (see above) — billing page will show the contact fallback."
ln -sfn "$REL" "$ROOT/current.new" && mv -Tf "$ROOT/current.new" "$ROOT/current"
"$PHP" artisan up >/dev/null 2>&1 || true

# Web folder → current/public (move any old static folder aside once).
WEB="${WEB_DIR:?WEB_DIR missing}"
case "$WEB" in /*) ;; *) WEB="$HOME/$WEB";; esac
if [ -e "$WEB" ] && [ ! -L "$WEB" ]; then mv "$WEB" "$WEB.static-$(date +%Y%m%d%H%M%S)"; fi
mkdir -p "$(dirname "$WEB")"; ln -sfn "$ROOT/current/public" "$WEB"

# Scheduler (reminders, queue, backups) — one cron line per environment.
LINE="* * * * * cd $ROOT/current && $PHP artisan schedule:run >> /dev/null 2>&1 # teamsayo-$ENV"
( crontab -l 2>/dev/null | grep -v "# teamsayo-$ENV\$" ; echo "$LINE" ) | crontab -

# Keep the last 5 releases.
ls -1dt "$ROOT"/releases/*/ | tail -n +6 | xargs -r rm -rf
echo "Deployed $SHA to $ENV → $WEB"
