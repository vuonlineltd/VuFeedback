<?php
/**
 * Plugin Name: VuFeedback
 * Description: On-page feedback pins for client review. Loads only for visitors with a review key. Settings > VuFeedback.
 * Version:     1.5.0
 * Author:      Vu Digital
 * Author URI:  https://vudigital.co.uk
 * Update URI:  false
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/slack.php';

define( 'VUFB_DIR', __DIR__ );

/**
 * A fresh key.
 *
 * @return string
 */
function vufb_key() {
	return bin2hex( random_bytes( 8 ) );
}

/* First activation: generate keys so it works out of the box. */
register_activation_hook(
	__FILE__,
	function () {
		$c = vufb_config();
		if ( ! $c['review_key'] ) {
			$c['review_key'] = vufb_key();
		}
		if ( ! $c['admin_key'] ) {
			$c['admin_key'] = vufb_key();
		}
		vufb_save_config( $c );
	}
);

/* Deactivation: stop any Slack summary that was waiting. */
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'vufb_slack_flush' );
	}
);

/*
 * The API goes through WordPress (admin-ajax.php): every host runs it as a
 * normal WordPress request, and none caches it. Visitors are not logged in, so
 * the nopriv hook is the one reviewers use; the key is the only permission.
 */
add_action( 'wp_ajax_vufeedback', 'vufb_ajax' );
add_action( 'wp_ajax_nopriv_vufeedback', 'vufb_ajax' );

/**
 * admin-ajax.php?action=vufeedback&op=<op>
 *
 * @return void
 */
function vufb_ajax() {
	vufb_run_api( isset( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : '' );
}

/* Front end: the widget, for everyone; it exits at once for visitors without a key. */
add_action(
	'wp_enqueue_scripts',
	function () {
		$c = vufb_config();
		if ( is_admin() || '1' !== $c['enabled'] || '' === $c['review_key'] ) {
			return;
		}
		wp_enqueue_script(
			'vufeedback',
			plugins_url( 'feedback.js', __FILE__ ),
			array(),
			filemtime( VUFB_DIR . '/feedback.js' ),
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_add_inline_script(
			'vufeedback',
			'window.VUFB_API=' . wp_json_encode( admin_url( 'admin-ajax.php?action=vufeedback' ) ) . ';window.VUFB_REVIEW=' . wp_json_encode( plugins_url( 'review.html', __FILE__ ) ) . ';',
			'before'
		);
	}
);

/* Settings page. */
add_action(
	'admin_menu',
	function () {
		add_options_page( 'VuFeedback', 'VuFeedback', 'manage_options', 'vufeedback', 'vufb_settings_page' );
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=vufeedback' ) ) . '">Settings</a>' );
		return $links;
	}
);

/**
 * Settings > VuFeedback.
 *
 * @return void
 */
function vufb_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$c     = vufb_config();
	$slack = vufb_slack_config();
	$msg   = '';

	if ( isset( $_POST['vufb_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['vufb_nonce'] ), 'vufb_save' ) ) {
		$c['review_key'] = preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_POST['review_key'] ?? '' ) ) ?: vufb_key();
		$c['admin_key']  = preg_replace( '/[^A-Za-z0-9_-]/', '', wp_unslash( $_POST['admin_key'] ?? '' ) ) ?: vufb_key();
		if ( isset( $_POST['regen_review'] ) ) {
			$c['review_key'] = vufb_key();
		}
		if ( isset( $_POST['regen_admin'] ) ) {
			$c['admin_key'] = vufb_key();
		}
		$c['enabled'] = isset( $_POST['enabled'] ) ? '1' : '0';
		$msg          = vufb_save_config( $c ) ? 'Saved.' : 'Could not save settings. Check the plugin\'s data folder is writable.';

		// The webhook is never printed back; a blank field keeps the one already saved.
		$new_url = trim( wp_unslash( $_POST['slack_url'] ?? '' ) );
		if ( isset( $_POST['slack_remove'] ) ) {
			$slack['url'] = '';
		} elseif ( '' !== $new_url ) {
			if ( vufb_slack_valid_url( $new_url ) ) {
				$slack['url'] = $new_url;
			} else {
				$msg .= ' The Slack address was not saved: it must be a Slack Incoming Webhook (https://hooks.slack.com/services/…).';
			}
		}
		$slack['skip_admin'] = isset( $_POST['slack_skip_admin'] ) ? '1' : '0';
		vufb_slack_save_config( $slack );

		if ( isset( $_POST['slack_test'] ) ) {
			$sent = vufb_slack_post( vufb_slack_site() . ' · VuFeedback is connected. New comments on this site will be posted here.', true );
			$msg .= true === $sent ? ' Test message sent to Slack.' : ' Slack test failed: ' . $sent;
		}
	}

	$home     = home_url( '/' );
	$base     = plugins_url( '', __FILE__ );
	$problem  = vufb_storage_problem();
	$attach   = vufb_attachments_problem();
	$api      = admin_url( 'admin-ajax.php?action=vufeedback' );
	$pins     = count( vufb_pins() );
	$bytes    = 0;
	foreach ( glob( VUFB_DATA_DIR . '/files/*' ) ?: array() as $f ) {
		if ( 'index.php' !== basename( $f ) ) {
			$bytes += filesize( $f );
		}
	}
	$maxup = min( wp_convert_hr_to_bytes( ini_get( 'upload_max_filesize' ) ), wp_convert_hr_to_bytes( ini_get( 'post_max_size' ) ) );
	?>
	<div class="wrap">
		<h1>VuFeedback</h1>
		<?php if ( $msg ) : ?>
			<div class="notice notice-info"><p><?php echo esc_html( $msg ); ?></p></div>
		<?php endif; ?>
		<?php if ( $problem ) : ?>
			<div class="notice notice-error"><p><strong>Comments cannot be saved.</strong> <?php echo esc_html( $problem ); ?> The folder is <code><?php echo esc_html( VUFB_DATA_DIR ); ?></code>; it must be writable by PHP.</p></div>
		<?php else : ?>
			<div class="notice notice-success inline"><p>Storage check passed: comments can be saved. They are kept in the WordPress database; attachments go in <code><?php echo esc_html( str_replace( ABSPATH, '', VUFB_DATA_DIR . '/files' ) ); ?></code>.</p></div>
		<?php endif; ?>
		<?php if ( $attach ) : ?>
			<div class="notice notice-warning inline"><p><strong>Attachments may not save.</strong> <?php echo esc_html( $attach ); ?> Comments are unaffected.</p></div>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'vufb_save', 'vufb_nonce' ); ?>
			<table class="form-table">
				<tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" <?php checked( $c['enabled'], '1' ); ?>> Load the feedback widget on the front end</label><p class="description">Visitors without a key never see anything either way. Untick when the review is over.</p></td></tr>
				<tr><th><label for="review_key">Client key</label></th><td><input type="text" id="review_key" name="review_key" value="<?php echo esc_attr( $c['review_key'] ); ?>" class="regular-text code"> <button class="button" name="regen_review">Regenerate</button><p class="description">Lets people add comments and reply.</p></td></tr>
				<tr><th><label for="admin_key">Admin key</label></th><td><input type="text" id="admin_key" name="admin_key" value="<?php echo esc_attr( $c['admin_key'] ); ?>" class="regular-text code"> <button class="button" name="regen_admin">Regenerate</button><p class="description">Everything above plus change status and delete. Keep this one to yourself.</p></td></tr>
				<tr><th><label for="slack_url">Slack</label></th><td>
					<?php if ( $slack['url'] ) : ?>
						<p><strong>Connected</strong> to the webhook ending <code>…<?php echo esc_html( substr( $slack['url'], -4 ) ); ?></code>. <label><input type="checkbox" name="slack_remove"> Disconnect</label></p>
					<?php endif; ?>
					<input type="password" id="slack_url" name="slack_url" value="" class="large-text code" autocomplete="off" placeholder="<?php echo $slack['url'] ? 'Paste a new webhook to replace it' : 'https://hooks.slack.com/services/…'; ?>">
					<p class="description">The site's channel &rarr; add the <em>Incoming Webhooks</em> app &rarr; copy its Webhook URL. Kept on the server only; reviewers never see it. The first comment is posted straight away, and anything after it within five minutes is posted as one summary.</p>
					<p><label><input type="checkbox" name="slack_skip_admin" <?php checked( $slack['skip_admin'], '1' ); ?>> Don't post comments made with the admin key (our own)</label></p>
					<?php if ( $slack['url'] ) : ?>
						<button class="button" name="slack_test" value="1">Save and send a test message</button>
					<?php endif; ?>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php if ( $c['review_key'] ) : ?>
			<h2>Links</h2>
			<table class="widefat striped" style="max-width:900px">
				<tr><td><strong>Send to client</strong></td><td><code><?php echo esc_html( $home . '?review=' . $c['review_key'] ); ?></code></td></tr>
				<tr><td><strong>Your on-page link</strong></td><td><code><?php echo esc_html( $home . '?review=' . $c['admin_key'] ); ?></code></td></tr>
				<tr><td><strong>Review all comments</strong></td><td><a href="<?php echo esc_url( $base . '/review.html?key=' . rawurlencode( $c['admin_key'] ) . '&api=' . rawurlencode( $api ) ); ?>" target="_blank" class="button button-primary">Open review page</a> &nbsp; <?php echo (int) $pins; ?> comment<?php echo 1 === $pins ? '' : 's'; ?> so far<?php echo $bytes ? ', ' . esc_html( size_format( $bytes ) ) . ' of attachments' : ''; ?></td></tr>
				<tr><td><strong>Upload limit</strong></td><td>Reviewers can attach files up to <?php echo esc_html( size_format( min( $maxup, 25 * 1048576 ) ) ); ?> each (server limit <?php echo esc_html( size_format( $maxup ) ); ?>).</td></tr>
				<tr><td><strong>Switch off in a browser</strong></td><td><code>?review=off</code></td></tr>
			</table>
			<p class="description">Open the link once and the key is remembered in that browser, so reviewers can move around the site normally.</p>
		<?php endif; ?>
	</div>
	<?php
}
