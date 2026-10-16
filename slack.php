<?php
/**
 * Slack: a nod in the site's channel when a reviewer adds something.
 *
 * WordPress only. The channel's Incoming Webhook URL is kept in its own option
 * and only ever used by the server: it is never sent to a browser, the review
 * page or the CSV, so nobody reviewing the site can find it and post to the
 * channel themselves.
 *
 * Reviewers often leave thirty comments in one sitting, so the first one is
 * posted straight away and anything after it inside the next few minutes is
 * held and posted as one summary ("Hugh added 12 more comments on…"). That also
 * caps the channel at one message per window, however busy (or abusive) the
 * reviewing gets.
 *
 * What a reviewer typed is only ever plain text in Slack: <, > and & are
 * escaped, so a comment cannot @channel, mention anyone or disguise a link.
 *
 * @package VuFeedback
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VUFB_SLACK_WINDOW  = 300;   // Seconds between messages; later activity waits for the summary.
const VUFB_SLACK_EXCERPT = 200;   // Characters of the comment quoted in the first message.
const VUFB_SLACK_PAGES   = 20;    // Distinct pages remembered for a summary.

add_action( 'vufb_slack_flush', 'vufb_slack_flush' );

/**
 * Settings: url (the Incoming Webhook) and skip_admin (don't post our own comments).
 *
 * @return array{url:string, skip_admin:string}
 */
function vufb_slack_config() {
	$c = vufb_db_get( 'vufeedback_slack' ) ?: array();
	return array(
		'url'        => (string) ( $c['url'] ?? '' ),
		'skip_admin' => (string) ( $c['skip_admin'] ?? '1' ),
	);
}

/**
 * Save the Slack settings.
 *
 * @param array $c Settings.
 * @return bool
 */
function vufb_slack_save_config( array $c ) {
	return vufb_db_set( 'vufeedback_slack', $c );
}

/**
 * Whether a URL is a Slack Incoming Webhook. Only these are accepted, so the
 * setting can't be turned into a way of making the server call anything else.
 *
 * @param string $url URL.
 * @return bool
 */
function vufb_slack_valid_url( $url ) {
	return (bool) preg_match( '#^https://hooks\.slack\.com/services/[A-Za-z0-9/_-]+$#', $url );
}

/**
 * Reviewer text as plain Slack text: one line, trimmed, with Slack's three
 * control characters escaped.
 *
 * @param string $s   Text.
 * @param int    $max Characters kept.
 * @return string
 */
function vufb_slack_text( $s, $max = 0 ) {
	$s = trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
	if ( $max && mb_strlen( $s ) > $max ) {
		$s = rtrim( mb_substr( $s, 0, $max - 1 ) ) . '…';
	}
	return str_replace( array( '&', '<', '>' ), array( '&amp;', '&lt;', '&gt;' ), $s );
}

/**
 * A page path as a Slack link to that page on this site (no key in it).
 * Anything that isn't a plain path on this site is shown as text only.
 *
 * @param string $page Path the reviewer was on.
 * @return string
 */
function vufb_slack_page( $page ) {
	$page = (string) $page;
	$path = (string) wp_parse_url( $page, PHP_URL_PATH );
	if ( '' === $path || '/' !== $page[0] || 0 === strpos( $page, '//' ) || wp_parse_url( $page, PHP_URL_HOST ) ) {
		return vufb_slack_text( $page ? $page : '/' );
	}
	$url = str_replace( array( '|', '<', '>' ), '', esc_url_raw( home_url( $path ) ) );
	return '<' . $url . '|' . vufb_slack_text( $path ) . '>';
}

/**
 * The site's name, for the start of each message.
 *
 * @return string
 */
function vufb_slack_site() {
	return '*' . vufb_slack_text( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), 80 ) . '*';
}

/**
 * Post a message to the channel.
 *
 * @param string $text     Slack mrkdwn.
 * @param bool   $blocking Wait for Slack's answer (the settings test does).
 * @return true|string True, or why it failed.
 */
function vufb_slack_post( $text, $blocking = false ) {
	$url = vufb_slack_config()['url'];
	if ( ! vufb_slack_valid_url( $url ) ) {
		return 'No Slack webhook is set.';
	}
	$res = wp_remote_post(
		$url,
		array(
			'timeout'     => $blocking ? 8 : 2,
			'blocking'    => $blocking,
			'redirection' => 0,
			'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
			'body'        => wp_json_encode(
				array(
					'text'         => $text,
					'unfurl_links' => false,
					'unfurl_media' => false,
				)
			),
		)
	);
	if ( ! $blocking ) {
		return true;
	}
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	return 200 === $code ? true : 'Slack answered ' . $code . ': ' . wp_remote_retrieve_body( $res );
}

/**
 * A reviewer added a comment or a reply. Post it now if the channel has been
 * quiet for the window, otherwise add it to the next summary.
 *
 * @param string $kind     comment|reply.
 * @param string $author   Who.
 * @param string $page     Page path.
 * @param string $text     What they wrote.
 * @param bool   $is_admin Made with the admin key (us).
 * @return void
 */
function vufb_slack_notify( $kind, $author, $page, $text, $is_admin ) {
	$c = vufb_slack_config();
	if ( ! vufb_slack_valid_url( $c['url'] ) || ( $is_admin && '1' === $c['skip_admin'] ) ) {
		return;
	}
	if ( ! vufb_db_lock() ) {
		return; // A missed nod is better than a reviewer kept waiting.
	}

	$now   = time();
	$state = vufb_db_get( 'vufeedback_slack_state' ) ?: array();
	$queue = $state['queue'] ?? array();

	if ( ! $queue && $now - (int) ( $state['last'] ?? 0 ) >= VUFB_SLACK_WINDOW ) {
		$state['last'] = $now;
		vufb_db_set( 'vufeedback_slack_state', $state );
		vufb_db_unlock();
		$who = vufb_slack_text( $author ? $author : 'Someone', 80 );
		$did = 'reply' === $kind ? 'replied to a comment on' : 'left a comment on';
		vufb_slack_post( vufb_slack_site() . ' · ' . $who . ' ' . $did . ' ' . vufb_slack_page( $page ) . "\n>" . vufb_slack_text( $text, VUFB_SLACK_EXCERPT ) );
		return;
	}

	$queue[ 'reply' === $kind ? 'replies' : 'comments' ] = (int) ( $queue[ 'reply' === $kind ? 'replies' : 'comments' ] ?? 0 ) + 1;
	$who                       = mb_substr( trim( (string) $author ) ?: 'Someone', 0, 80 );
	$queue['authors'][ $who ]  = true;
	$queue['pages']            = $queue['pages'] ?? array();
	if ( count( $queue['pages'] ) < VUFB_SLACK_PAGES ) {
		$queue['pages'][ mb_substr( (string) $page, 0, 300 ) ] = true;
	} else {
		$queue['more_pages'] = true;
	}
	$state['queue'] = $queue;
	vufb_db_set( 'vufeedback_slack_state', $state );
	vufb_db_unlock();

	$due = (int) ( $state['last'] ?? $now ) + VUFB_SLACK_WINDOW;
	if ( ! wp_next_scheduled( 'vufb_slack_flush' ) ) {
		wp_schedule_single_event( max( $due, $now + 1 ), 'vufb_slack_flush' );
	}
}

/**
 * Post the summary if one is waiting and its window has passed. Runs from
 * WP-Cron, and from every VuFeedback request as a backstop for hosts where
 * cron runs late.
 *
 * @return void
 */
function vufb_slack_flush() {
	$state = vufb_db_get( 'vufeedback_slack_state' ) ?: array();
	if ( empty( $state['queue'] ) || time() - (int) ( $state['last'] ?? 0 ) < VUFB_SLACK_WINDOW ) {
		return;
	}
	if ( ! vufb_db_lock() ) {
		return;
	}
	$state = vufb_db_get( 'vufeedback_slack_state' ) ?: array();
	$queue = $state['queue'] ?? array();
	if ( ! $queue || time() - (int) ( $state['last'] ?? 0 ) < VUFB_SLACK_WINDOW ) {
		vufb_db_unlock();
		return;
	}
	$state['queue'] = array();
	$state['last']  = time();
	vufb_db_set( 'vufeedback_slack_state', $state );
	vufb_db_unlock();

	$counts = array();
	foreach ( array( 'comments' => 'comment', 'replies' => 'reply' ) as $key => $word ) {
		$n = (int) ( $queue[ $key ] ?? 0 );
		if ( $n ) {
			$counts[] = $n . ' more ' . ( 1 === $n ? $word : ( 'reply' === $word ? 'replies' : 'comments' ) );
		}
	}
	$authors = array_map( 'vufb_slack_text', array_keys( $queue['authors'] ?? array() ) );
	$pages   = array_map( 'vufb_slack_page', array_keys( $queue['pages'] ?? array() ) );
	$where   = count( $pages ) > 4 || ! empty( $queue['more_pages'] )
		? implode( ', ', array_slice( $pages, 0, 3 ) ) . ' and other pages'
		: vufb_slack_list( $pages );

	vufb_slack_post( vufb_slack_site() . ' · ' . vufb_slack_list( $authors ) . ' added ' . implode( ' and ', $counts ) . ' on ' . $where . '.' );
}

/**
 * "a", "a and b", "a, b and c".
 *
 * @param string[] $items Items.
 * @return string
 */
function vufb_slack_list( array $items ) {
	if ( count( $items ) < 2 ) {
		return (string) ( $items[0] ?? '' );
	}
	$last = array_pop( $items );
	return implode( ', ', $items ) . ' and ' . $last;
}
