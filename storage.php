<?php
/**
 * VuFeedback storage.
 *
 * WordPress: settings and comments are kept in the database (options
 * `vufeedback_config` and `vufeedback_pins`), like any other WordPress data.
 * On WP Engine, PHP requested directly from a plugin folder cannot write files
 * at all, while database writes work everywhere; and the database is the same
 * from every server a host may route a request to. Attachments still go to
 * wp-content/uploads/vufeedback-<random>/files/.
 *
 * Plain HTML site (no WordPress): everything is kept on disk, as below.
 *
 * Both are JSON, but saved as `.php` files that begin with an exit line, so a
 * direct web request for them returns an empty page instead of the contents.
 * (Earlier versions saved `.json` files and relied on an Apache `.htaccess` to
 * hide them. nginx, which Herd and WP Engine use for static files, ignores
 * `.htaccess`, so the admin key and every comment were publicly readable.)
 *
 * Where the data lives. On WordPress: wp-content/uploads/vufeedback-<random>/.
 * The uploads folder is writable on every host, where the plugin's own folder
 * often is not (WP Engine makes plugin folders read-only to PHP, which showed
 * as "Failed: storage not writable"). The random folder name means it cannot
 * be guessed, and it also carries an Apache deny rule and an index.php. On a
 * plain HTML site there is no uploads folder, so it stays in ./data.
 *
 * Attachments live in <data>/files/ under random names that are only listed in
 * the protected pins file, so they cannot be found without a key.
 *
 * Shared by feedback.php (the API, which runs without WordPress) and
 * vufeedback.php (the WordPress settings page).
 */

const VUFB_GUARD      = "<?php exit; ?>\n";
const VUFB_PLUGIN_DATA = __DIR__ . '/data';

/**
 * Lock a data folder against browsing and direct requests.
 *
 * @param string $dir Folder.
 * @return void
 */
function vufb_protect_dir( $dir ) {
	if ( ! is_file( $dir . '/.htaccess' ) ) {
		@file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
	}
	if ( ! is_file( $dir . '/index.php' ) ) {
		@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
	}
}

/**
 * The data folder: wp-content/uploads/vufeedback-<random>/ when installed as a
 * WordPress plugin, otherwise ./data.
 *
 * @return string
 */
function vufb_locate_data_dir() {
	$plugins = dirname( __DIR__ );
	$uploads = dirname( $plugins ) . '/uploads';

	if ( 'plugins' !== basename( $plugins ) || ! is_dir( $uploads ) ) {
		return VUFB_PLUGIN_DATA;
	}

	$existing = glob( $uploads . '/vufeedback-*', GLOB_ONLYDIR ) ?: array();
	sort( $existing );
	if ( $existing ) {
		return $existing[0];
	}

	$dir = $uploads . '/vufeedback-' . bin2hex( random_bytes( 8 ) );
	if ( ! @mkdir( $dir, 0755 ) ) {
		return VUFB_PLUGIN_DATA; // Uploads not writable either: fall back, and the settings page will say so.
	}

	// Two first requests at once can each create a folder: keep the first by name.
	$existing = glob( $uploads . '/vufeedback-*', GLOB_ONLYDIR ) ?: array();
	sort( $existing );
	if ( $existing[0] !== $dir ) {
		@rmdir( $dir );
		return $existing[0];
	}

	vufb_protect_dir( $dir );
	return $dir;
}

define( 'VUFB_DATA_DIR', vufb_locate_data_dir() );
define( 'VUFB_PINS_FILE', VUFB_DATA_DIR . '/pins.php' );
define( 'VUFB_CONF_FILE', VUFB_DATA_DIR . '/config.php' );
define( 'VUFB_LOCK_DIR', VUFB_DATA_DIR . '/.lock' );

/*
 * Locking. PHP's flock() fails on network storage (WP Engine and other managed
 * hosts keep sites on NFS), which showed as "Failed: lock". Creating a
 * directory is atomic on every filesystem, NFS included, so that is the lock;
 * and every save writes a temporary file and renames it over the real one, so
 * a file is never left half-written. The temporary name ends in .php and
 * starts with the guard line, so it is never readable from the web either.
 */

/**
 * Take the lock, waiting up to five seconds. A lock older than 30 seconds is
 * left over from a crashed request and is cleared.
 *
 * @return bool
 */
function vufb_lock() {
	$start = microtime( true );
	while ( ! @mkdir( VUFB_LOCK_DIR, 0755 ) ) {
		clearstatcache( true, VUFB_LOCK_DIR );
		if ( is_dir( VUFB_LOCK_DIR ) && time() - (int) @filemtime( VUFB_LOCK_DIR ) > 30 ) {
			@rmdir( VUFB_LOCK_DIR );
			continue;
		}
		if ( ! is_dir( VUFB_LOCK_DIR ) && ! is_writable( VUFB_DATA_DIR ) ) {
			return false; // Not a busy lock: the folder cannot be written at all.
		}
		if ( microtime( true ) - $start > 5 ) {
			return false;
		}
		usleep( 50000 );
	}
	return true;
}

/**
 * Release the lock.
 *
 * @return void
 */
function vufb_unlock() {
	@rmdir( VUFB_LOCK_DIR );
}

/**
 * Write a file atomically: temporary file, then rename over the target.
 *
 * @param string $file     Target.
 * @param string $contents Contents.
 * @return bool
 */
function vufb_write( $file, $contents ) {
	$tmp = $file . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp.php';
	if ( @file_put_contents( $tmp, $contents ) === false ) {
		return false;
	}
	if ( ! @rename( $tmp, $file ) ) {
		@unlink( $tmp );
		return false;
	}
	return true;
}

/**
 * Why storage isn't working, or '' if it is. Used by the settings page.
 *
 * @return string
 */
function vufb_file_storage_problem() {
	if ( ! is_dir( VUFB_DATA_DIR ) && ! @mkdir( VUFB_DATA_DIR, 0755, true ) ) {
		return 'The data folder does not exist and could not be created.';
	}
	if ( ! is_writable( VUFB_DATA_DIR ) ) {
		return VUFB_DATA_DIR === VUFB_PLUGIN_DATA && basename( dirname( __DIR__ ) ) === 'plugins'
			? 'Neither wp-content/uploads nor the plugin\'s own data folder is writable by PHP.'
			: 'The data folder is not writable by PHP.';
	}
	if ( ! vufb_lock() ) {
		return 'Could not take the save lock in the data folder.';
	}
	$probe = VUFB_DATA_DIR . '/probe.php';
	$ok    = vufb_write( $probe, VUFB_GUARD . 'ok' );
	@unlink( $probe );
	vufb_unlock();
	return $ok ? '' : 'Could not write a file in the data folder.';
}

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
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$sources = array_unique( array( VUFB_DATA_DIR, VUFB_PLUGIN_DATA ) );

	foreach ( array( 'pins' => VUFB_PINS_FILE, 'config' => VUFB_CONF_FILE ) as $name => $target ) {
		foreach ( $sources as $dir ) {
			foreach ( array( $dir . '/' . $name . '.json', $dir . '/' . $name . '.php' ) as $legacy ) {
				if ( $legacy === $target || ! is_file( $legacy ) ) {
					continue;
				}
				if ( ! is_file( $target ) ) {
					$raw  = (string) file_get_contents( $legacy );
					$data = '.json' === substr( $legacy, -5 ) ? json_decode( $raw, true ) : vufb_decode( $raw );
					if ( ! is_array( $data ) || ! vufb_write( $target, vufb_encode( $data ) ) ) {
						continue; // Not writable: leave the old file rather than lose it.
					}
				}
				@unlink( $legacy ); // May fail on a read-only plugin folder; harmless, the new copy wins.
			}
		}
	}

	// Attachments from the plugin's own folder.
	if ( VUFB_DATA_DIR !== VUFB_PLUGIN_DATA && is_dir( VUFB_PLUGIN_DATA . '/files' ) ) {
		@mkdir( VUFB_DATA_DIR . '/files', 0755 );
		foreach ( glob( VUFB_PLUGIN_DATA . '/files/*' ) ?: array() as $f ) {
			$to = VUFB_DATA_DIR . '/files/' . basename( $f );
			if ( ! is_file( $to ) ) {
				@copy( $f, $to );
			}
		}
	}
}

/**
 * Settings: review_key, admin_key, enabled.
 *
 * @return array
 */
function vufb_file_config() {
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
function vufb_file_save_config( array $c ) {
	if ( ! is_dir( VUFB_DATA_DIR ) ) {
		mkdir( VUFB_DATA_DIR, 0755, true );
	}
	return vufb_write( VUFB_CONF_FILE, vufb_encode( $c ) );
}

/**
 * All pins.
 *
 * @return array
 */
function vufb_file_pins() {
	vufb_migrate_legacy();
	return is_file( VUFB_PINS_FILE ) ? vufb_decode( (string) file_get_contents( VUFB_PINS_FILE ) ) : array();
}

/**
 * Change the pins under the lock. The callback receives the pins by reference
 * and its return value is passed back. On failure, returns a RuntimeException
 * whose message is shown to the reviewer.
 *
 * @param callable $mutate Callback.
 * @return mixed
 */
function vufb_file_save_pins( callable $mutate ) {
	vufb_migrate_legacy();
	if ( ! is_dir( VUFB_DATA_DIR ) ) {
		@mkdir( VUFB_DATA_DIR, 0755, true );
	}
	if ( ! is_writable( VUFB_DATA_DIR ) ) {
		return new RuntimeException( 'storage not writable' );
	}
	if ( ! vufb_lock() ) {
		return new RuntimeException( 'busy, please try again' );
	}
	clearstatcache( true, VUFB_PINS_FILE );
	$pins   = is_file( VUFB_PINS_FILE ) ? vufb_decode( (string) file_get_contents( VUFB_PINS_FILE ) ) : array();
	$result = $mutate( $pins );
	$saved  = vufb_write( VUFB_PINS_FILE, vufb_encode( array_values( $pins ) ) );
	vufb_unlock();
	return $saved ? $result : new RuntimeException( 'storage not writable' );
}

/* ------------------------------------------------------------------------
 * Backend switch: WordPress database when WordPress is loaded, files when not.
 * --------------------------------------------------------------------- */

/**
 * Whether WordPress is loaded, so the database can be used.
 *
 * @return bool
 */
function vufb_using_db() {
	return function_exists( 'get_option' ) && isset( $GLOBALS['wpdb'] );
}

/**
 * Read an option straight from the database, bypassing every cache, so the
 * value read under the lock is the value actually stored.
 *
 * @param string $name Option name.
 * @return array|null Null when the option does not exist.
 */
function vufb_db_get( $name ) {
	global $wpdb;
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
	if ( null === $raw ) {
		return null;
	}
	$data = maybe_unserialize( $raw );
	return is_array( $data ) ? $data : array();
}

/**
 * Store an option (not autoloaded: it is only needed by VuFeedback requests).
 *
 * @param string $name Option name.
 * @param array  $data Value.
 * @return bool
 */
function vufb_db_set( $name, array $data ) {
	wp_cache_delete( $name, 'options' );
	if ( null === vufb_db_get( $name ) ) {
		return (bool) add_option( $name, $data, '', false );
	}
	update_option( $name, $data, false );
	return vufb_db_get( $name ) === $data;
}

/**
 * One-off import of file data (1.3.x, or 1.2 .json) into the database.
 *
 * @return void
 */
function vufb_db_import_files() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	if ( null === vufb_db_get( 'vufeedback_config' ) ) {
		$c = vufb_file_config();
		if ( '' !== $c['review_key'] || '' !== $c['admin_key'] ) {
			vufb_db_set( 'vufeedback_config', $c );
		}
	}
	if ( null === vufb_db_get( 'vufeedback_pins' ) ) {
		$pins = vufb_file_pins();
		if ( $pins ) {
			vufb_db_set( 'vufeedback_pins', array_values( $pins ) );
		}
	}
}

/**
 * Database lock: a single option row. Inserting it either succeeds or finds
 * it already there, atomically, on every server sharing the database.
 *
 * @return bool
 */
function vufb_db_lock() {
	global $wpdb;
	$start = microtime( true );
	do {
		$got = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES ('vufeedback_lock', %s, 'off')", (string) time() ) );
		if ( 1 === $got ) {
			return true;
		}
		$since = (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'vufeedback_lock'" );
		if ( $since && time() - $since > 30 ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => 'vufeedback_lock' ) ); // Left by a crashed request.
			continue;
		}
		usleep( 50000 );
	} while ( microtime( true ) - $start < 5 );
	return false;
}

/**
 * Release the database lock.
 *
 * @return void
 */
function vufb_db_unlock() {
	global $wpdb;
	$wpdb->delete( $wpdb->options, array( 'option_name' => 'vufeedback_lock' ) );
}

/**
 * Settings: review_key, admin_key, enabled.
 *
 * @return array
 */
function vufb_config() {
	if ( ! vufb_using_db() ) {
		return vufb_file_config();
	}
	vufb_db_import_files();
	$c = vufb_db_get( 'vufeedback_config' ) ?: array();
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
	return vufb_using_db() ? vufb_db_set( 'vufeedback_config', $c ) : vufb_file_save_config( $c );
}

/**
 * All pins.
 *
 * @return array
 */
function vufb_pins() {
	if ( ! vufb_using_db() ) {
		return vufb_file_pins();
	}
	vufb_db_import_files();
	return vufb_db_get( 'vufeedback_pins' ) ?: array();
}

/**
 * Change the pins under the lock. The callback receives the pins by reference
 * and its return value is passed back. On failure, returns a RuntimeException
 * whose message is shown to the reviewer.
 *
 * @param callable $mutate Callback.
 * @return mixed
 */
function vufb_save_pins( callable $mutate ) {
	if ( ! vufb_using_db() ) {
		return vufb_file_save_pins( $mutate );
	}
	vufb_db_import_files();
	if ( ! vufb_db_lock() ) {
		return new RuntimeException( 'busy, please try again' );
	}
	$pins   = vufb_db_get( 'vufeedback_pins' ) ?: array();
	$result = $mutate( $pins );
	$saved  = vufb_db_set( 'vufeedback_pins', array_values( $pins ) );
	vufb_db_unlock();
	return $saved ? $result : new RuntimeException( 'could not save to the database' );
}

/**
 * Why comments can't be saved, or '' if they can. Settings page.
 *
 * @return string
 */
function vufb_storage_problem() {
	if ( ! vufb_using_db() ) {
		return vufb_file_storage_problem();
	}
	if ( ! vufb_db_lock() ) {
		return 'Could not take the save lock in the database.';
	}
	vufb_db_unlock();
	return '';
}

/**
 * Whether attachments can be saved, or why not. Settings page.
 *
 * @return string
 */
function vufb_attachments_problem() {
	$dir = VUFB_DATA_DIR . '/files';
	if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) ) {
		return 'The attachments folder could not be created.';
	}
	return is_writable( $dir ) ? '' : 'The attachments folder is not writable.';
}
