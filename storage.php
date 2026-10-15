<?php
/**
 * VuFeedback storage: settings and pins on disk, safe on any web server.
 *
 * Both are JSON, but saved as `.php` files that begin with an exit line, so a
 * direct web request for them returns an empty page instead of the contents.
 * (Earlier versions saved `.json` files and relied on an Apache `.htaccess` to
 * hide them. nginx, which Herd and WP Engine use for static files, ignores
 * `.htaccess`, so the admin key and every comment were publicly readable.)
 *
 * Attachments live in data/files/ under random names that are only listed in
 * the protected pins file, so they cannot be found without a key.
 *
 * Shared by feedback.php (the API, which runs without WordPress) and
 * vufeedback.php (the WordPress settings page).
 */

const VUFB_DATA_DIR  = __DIR__ . '/data';
const VUFB_PINS_FILE = VUFB_DATA_DIR . '/pins.php';
const VUFB_CONF_FILE = VUFB_DATA_DIR . '/config.php';
const VUFB_GUARD     = "<?php exit; ?>\n";

/**
 * Decode a guarded data file's contents.
 *
 * @param string $raw File contents.
 * @return array
 */
function vufb_decode( $raw ) {
	if ( strpos( $raw, VUFB_GUARD ) === 0 ) {
		$raw = substr( $raw, strlen( VUFB_GUARD ) );
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Encode data for a guarded file.
 *
 * @param array $data Data.
 * @return string
 */
function vufb_encode( array $data ) {
	return VUFB_GUARD . json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Move data saved by v1.2 and earlier (public .json files) into the guarded
 * files, then delete the old ones. Runs on every read; costs a stat when there
 * is nothing to move.
 *
 * @return void
 */
function vufb_migrate_legacy() {
	foreach ( array( 'pins' => VUFB_PINS_FILE, 'config' => VUFB_CONF_FILE ) as $name => $target ) {
		$legacy = VUFB_DATA_DIR . '/' . $name . '.json';
		if ( ! is_file( $legacy ) ) {
			continue;
		}
		if ( ! is_file( $target ) ) {
			$data = json_decode( (string) file_get_contents( $legacy ), true );
			if ( is_array( $data ) && file_put_contents( $target, vufb_encode( $data ), LOCK_EX ) === false ) {
				continue; // Not writable: leave the old file rather than lose it.
			}
		}
		@unlink( $legacy );
	}
}

/**
 * Settings: review_key, admin_key, enabled.
 *
 * @return array
 */
function vufb_config() {
	vufb_migrate_legacy();
	$c = is_file( VUFB_CONF_FILE ) ? vufb_decode( (string) file_get_contents( VUFB_CONF_FILE ) ) : array();
	return array(
		'review_key' => (string) ( $c['review_key'] ?? '' ),
		'admin_key'  => (string) ( $c['admin_key'] ?? '' ),
		'enabled'    => (string) ( $c['enabled'] ?? '1' ),
	);
}

/**
 * Save settings.
 *
 * @param array $c Settings.
 * @return bool
 */
function vufb_save_config( array $c ) {
	if ( ! is_dir( VUFB_DATA_DIR ) ) {
		mkdir( VUFB_DATA_DIR, 0755, true );
	}
	return file_put_contents( VUFB_CONF_FILE, vufb_encode( $c ), LOCK_EX ) !== false;
}

/**
 * All pins.
 *
 * @return array
 */
function vufb_pins() {
	vufb_migrate_legacy();
	return is_file( VUFB_PINS_FILE ) ? vufb_decode( (string) file_get_contents( VUFB_PINS_FILE ) ) : array();
}

/**
 * Change the pins under an exclusive lock. The callback receives the pins by
 * reference and its return value is passed back.
 *
 * @param callable $mutate Callback.
 * @return mixed
 */
function vufb_save_pins( callable $mutate ) {
	vufb_migrate_legacy();
	if ( ! is_dir( VUFB_DATA_DIR ) ) {
		mkdir( VUFB_DATA_DIR, 0755, true );
	}
	$fh = fopen( VUFB_PINS_FILE, 'c+' );
	if ( ! $fh || ! flock( $fh, LOCK_EX ) ) {
		return new RuntimeException( 'lock' );
	}
	$pins   = vufb_decode( (string) stream_get_contents( $fh ) );
	$result = $mutate( $pins );
	ftruncate( $fh, 0 );
	rewind( $fh );
	fwrite( $fh, vufb_encode( array_values( $pins ) ) );
	fflush( $fh );
	flock( $fh, LOCK_UN );
	fclose( $fh );
	return $result;
}
