<?php
/* Minimal stand-in for `wp eval-file`: php run.php <script.php> */
if ( 'install.php' === basename( $argv[1] ) ) { define( 'WP_INSTALLING', true ); }
$_SERVER['HTTP_HOST']   = getenv( 'PSHYK_HOST' ) ?: 'localhost:8080';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_NAME'] = 'localhost';
require __DIR__ . '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
include $argv[1];
