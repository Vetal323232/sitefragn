#!/usr/bin/env bash
# Local staging for pshyk-boutique: WordPress + WooCommerce (SQLite, PHP built-in server), Storefront theme,
# 132 real products/46 reviews snapshot (staging/data), the store-behaviour shim and the plugin.
# Needs: php 8.1+ (pdo_sqlite, gd or imagick, intl, mbstring, zip), curl, unzip. No MySQL, no Docker.
# Usage: bash staging/setup.sh [port]          → then open http://localhost:<port>/  (admin / admin-staging)
set -euo pipefail
PORT="${1:-8080}"
HERE="$(cd "$(dirname "$0")" && pwd)"
REPO="$(dirname "$HERE")"
WP="$HERE/wordpress"
CACHE="${PSHYK_CACHE:-$HERE/.cache}"
mkdir -p "$CACHE"

fetch() { [ -s "$CACHE/$1" ] || curl -fsSL "$2" -o "$CACHE/$1"; }
fetch wp.zip         https://wordpress.org/latest.zip
fetch woo.zip        https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip
fetch sqlite.zip     https://downloads.wordpress.org/plugin/sqlite-database-integration.latest-stable.zip
fetch storefront.zip https://downloads.wordpress.org/theme/storefront.latest-stable.zip

if [ ! -f "$WP/wp-settings.php" ]; then
	unzip -q "$CACHE/wp.zip" -d "$HERE"
	unzip -q "$CACHE/woo.zip" -d "$WP/wp-content/plugins"
	unzip -q "$CACHE/sqlite.zip" -d "$WP/wp-content/plugins"
	unzip -q "$CACHE/storefront.zip" -d "$WP/wp-content/themes"
	sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$WP/wp-content/plugins/sqlite-database-integration#" \
	    -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	    "$WP/wp-content/plugins/sqlite-database-integration/db.copy" > "$WP/wp-content/db.php"
	mkdir -p "$WP/wp-content/mu-plugins" "$WP/wp-content/database"
	sed "s#localhost:8080#localhost:$PORT#g" "$HERE/wp-config.staging.php" > "$WP/wp-config.php"
fi
cp "$HERE"/mu-plugins/*.php "$WP/wp-content/mu-plugins/"
ln -sfn "$REPO/pshyk-boutique" "$WP/wp-content/plugins/pshyk-boutique"

# Ukrainian language packs (optional; the store UI is Ukrainian either way).
if [ ! -d "$WP/wp-content/languages/plugins" ]; then
	mkdir -p "$WP/wp-content/languages/plugins" "$WP/wp-content/languages/themes"
	pack() { curl -fsSL "$1" 2>/dev/null | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach(($d["translations"]??[]) as $t){ if($t["language"]==="uk"){ echo $t["package"]; break; } }'; }
	WPVER="$(php -r 'include "'"$WP"'/wp-includes/version.php"; echo $wp_version;')"
	for spec in "core/1.0/?version=$WPVER|." "plugins/1.0/?slug=woocommerce|plugins" "themes/1.0/?slug=storefront|themes"; do
		url="$(pack "https://api.wordpress.org/translations/${spec%%|*}" || true)"
		[ -n "$url" ] && curl -fsSL "$url" -o "$CACHE/lang.zip" && unzip -qo "$CACHE/lang.zip" -d "$WP/wp-content/languages/${spec##*|}" || echo "language pack skipped: ${spec%%|*}"
	done
fi

export PSHYK_HOST="localhost:$PORT"
cd "$HERE"
php run.php install.php
PSHYK_DATA="$HERE/data/products.json" php -d memory_limit=1G run.php import-products.php
php run.php setup-boutique.php

echo
echo "Staging ready. Start the server:"
echo "  php -S localhost:$PORT -t \"$WP\" \"$HERE/router.php\""
echo "Then open http://localhost:$PORT/  — admin: http://localhost:$PORT/wp-admin  (admin / admin-staging)"
