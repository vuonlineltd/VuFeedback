<?php
/**
 * Deleting VuFeedback deletes its comments and settings, as the README says.
 * Attachments in wp-content/uploads/vufeedback-<random>/ are removed too.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'vufeedback_config' );
delete_option( 'vufeedback_pins' );
delete_option( 'vufeedback_lock' );
delete_option( 'vufeedback_slack' );
delete_option( 'vufeedback_slack_state' );
wp_clear_scheduled_hook( 'vufb_slack_flush' );

$vufb_uploads = wp_upload_dir( null, false );
foreach ( glob( trailingslashit( $vufb_uploads['basedir'] ) . 'vufeedback-*', GLOB_ONLYDIR ) ?: array() as $vufb_dir ) {
	$vufb_items = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $vufb_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $vufb_items as $vufb_item ) {
		$vufb_item->isDir() ? @rmdir( $vufb_item->getPathname() ) : @unlink( $vufb_item->getPathname() );
	}
	@rmdir( $vufb_dir );
}
