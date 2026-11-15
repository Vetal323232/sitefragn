<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8mb4' ); define( 'DB_COLLATE', '' );
define( 'DB_DIR', __DIR__ . '/wp-content/database/' ); define( 'DB_FILE', '.ht.sqlite' );
define( 'AUTH_KEY', 'local-staging-1' ); define( 'SECURE_AUTH_KEY', 'local-staging-2' ); define( 'LOGGED_IN_KEY', 'local-staging-3' ); define( 'NONCE_KEY', 'local-staging-4' );
define( 'AUTH_SALT', 'local-staging-5' ); define( 'SECURE_AUTH_SALT', 'local-staging-6' ); define( 'LOGGED_IN_SALT', 'local-staging-7' ); define( 'NONCE_SALT', 'local-staging-8' );
$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/wp-content/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'WP_HOME', 'http://localhost:8080' ); define( 'WP_SITEURL', 'http://localhost:8080' );
define( 'WPLANG', 'uk' ); define( 'DISABLE_WP_CRON', true ); define( 'AUTOMATIC_UPDATER_DISABLED', true );
// Safety: never send real e-mail from staging.
define( 'PSHYK_STAGING', true );
if ( ! defined( 'ABSPATH' ) ) define( 'ABSPATH', __DIR__ . '/' );
require_once ABSPATH . 'wp-settings.php';
