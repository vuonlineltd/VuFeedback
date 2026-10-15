<?php
/**
 * VuFeedback endpoint for plain HTML sites, and a fallback route on WordPress.
 *
 * On a plain HTML site this runs on its own and keeps everything in ./data.
 * Set the two keys below.
 *
 * Inside a WordPress plugin folder it loads WordPress first, so it reads and
 * writes the same database storage as the normal WordPress route
 * (admin-ajax.php?action=vufeedback). Pages cached before 1.4 still call this
 * file, so it has to give the same answers.
 */

// Plain HTML sites only: set fresh keys here (openssl rand -hex 8). WordPress ignores these.
const HTML_REVIEW_KEY = '';   // share this one with the client
const HTML_ADMIN_KEY  = '';   // keep this one for yourself

/**
 * wp-load.php when this file sits in wp-content/plugins/<folder>/, else ''.
 *
 * @return string
 */
function vufb_find_wp_load() {
	if ( 'plugins' !== basename( dirname( __DIR__ ) ) ) {
		return '';
	}
	$dir = dirname( __DIR__, 3 );
	for ( $i = 0; $i < 3; $i++, $dir = dirname( $dir ) ) {
		if ( is_file( $dir . '/wp-load.php' ) ) {
			return $dir . '/wp-load.php';
		}
	}
	return '';
}

$vufb_wp_load = vufb_find_wp_load();
if ( $vufb_wp_load ) {
	require_once $vufb_wp_load;
}

require_once __DIR__ . '/api.php';

vufb_run_api( (string) ( $_GET['action'] ?? '' ), HTML_REVIEW_KEY, HTML_ADMIN_KEY );
