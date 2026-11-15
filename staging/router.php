<?php
/* Router for `php -S`: serve existing files, send everything else to WordPress (pretty permalinks). */
$root = __DIR__ . '/wordpress';
$path = urldecode( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );
if ( '/' !== $path && is_file( $root . $path ) ) {
	return false;
}
if ( is_dir( $root . $path ) && is_file( rtrim( $root . $path, '/' ) . '/index.php' ) ) {
	chdir( rtrim( $root . $path, '/' ) );
	require rtrim( $root . $path, '/' ) . '/index.php';
	return;
}
chdir( $root );
require $root . '/index.php';
