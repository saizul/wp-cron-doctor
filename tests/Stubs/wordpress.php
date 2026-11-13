<?php
/**
 * Minimal WordPress function stubs backed by WpState.
 *
 * Only what includes/ uses. Cron functions emulate wp-includes/cron.php closely enough
 * to test the repair commands.
 *
 * @package Saizul\CronDoctor
 */

// phpcs:ignoreFile

declare(strict_types=1);

use Saizul\CronDoctor\Tests\Stubs\WpState;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		/** @var string */
		public $code;
		/** @var string */
		public $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_code(): string {
			return $this->code;
		}
	}
}

function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

function get_option( string $name, $default = false ) {
	return array_key_exists( $name, WpState::$options ) ? WpState::$options[ $name ] : $default;
}

function update_option( string $name, $value, $autoload = null ): bool {
	if ( array_key_exists( $name, WpState::$options ) && WpState::$options[ $name ] === $value ) {
		return false;
	}
	WpState::$options[ $name ] = $value;
	return true;
}

function delete_option( string $name ): bool {
	$existed = array_key_exists( $name, WpState::$options );
	unset( WpState::$options[ $name ] );
	return $existed;
}

function get_transient( string $name ) {
	return WpState::$transients[ $name ] ?? false;
}

function maybe_serialize( $data ) {
	if ( is_array( $data ) || is_object( $data ) ) {
		return serialize( $data );
	}
	return $data;
}

function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	WpState::$filters[ $hook ][] = $callback;
	return true;
}

function add_action( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): bool {
	WpState::$actions[ $hook ][] = $callback;
	return true;
}

function remove_filter( string $hook, $callback, int $priority = 10 ): bool {
	foreach ( WpState::$filters[ $hook ] ?? array() as $index => $registered ) {
		if ( $registered === $callback ) {
			unset( WpState::$filters[ $hook ][ $index ] );
			return true;
		}
	}
	return false;
}

function has_action( string $hook, $callback = false ) {
	return ! empty( WpState::$actions[ $hook ] ) ? 10 : false;
}

function apply_filters( string $hook, $value, ...$args ) {
	foreach ( WpState::$filters[ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}

function do_action_ref_array( string $hook, array $args ): void {
	WpState::$fired[] = array( $hook, $args );
	foreach ( WpState::$actions[ $hook ] ?? array() as $callback ) {
		call_user_func_array( $callback, array_values( $args ) ); // Like WordPress on PHP 7.4, string keys are not unpacked as names.
	}
}

function wp_doing_cron(): bool {
	return apply_filters( 'wp_doing_cron', WpState::$doing_cron );
}

function wp_get_schedules(): array {
	return WpState::$schedules;
}

function site_url( string $path = '' ): string {
	return WpState::$site_url . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}

function home_url( string $path = '' ): string {
	return WpState::$home_url . ( '' === $path ? '' : '/' . ltrim( $path, '/' ) );
}

function admin_url( string $path = '' ): string {
	return WpState::$site_url . '/wp-admin/' . ltrim( $path, '/' );
}

function get_site_url( $blog_id = null ): string {
	return WpState::$sites[ (int) $blog_id ] ?? WpState::$site_url;
}

function is_multisite(): bool {
	return WpState::$multisite;
}

function get_sites( array $args = array() ): array {
	return array_keys( WpState::$sites );
}

function untrailingslashit( string $value ): string {
	return rtrim( $value, '/\\' );
}

function remove_query_arg( string $key, string $url ): string {
	$parts = explode( '?', $url, 2 );
	if ( ! isset( $parts[1] ) ) {
		return $url;
	}
	parse_str( $parts[1], $query );
	unset( $query[ $key ] );
	return $parts[0] . ( array() === $query ? '' : '?' . http_build_query( $query ) );
}

function wp_remote_post( string $url, array $args = array() ) {
	WpState::$requests[] = array(
		'url'  => $url,
		'args' => $args,
	);
	return WpState::$remote_response ?? array(
		'response' => array( 'code' => 200 ),
		'headers'  => array(),
		'body'     => '',
	);
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? '';
}

function wp_remote_retrieve_header( $response, string $header ) {
	return $response['headers'][ strtolower( $header ) ] ?? '';
}

function wp_json_encode( $data, int $options = 0 ) {
	return json_encode( $data, $options );
}

/**
 * Emulates wp_unschedule_event() on the stored cron option.
 */
function wp_unschedule_event( int $timestamp, string $hook, array $args = array(), bool $wp_error = false ) {
	if ( null !== WpState::$unschedule_result ) {
		return WpState::$unschedule_result;
	}

	$crons = get_option( 'cron', array() );
	$key   = md5( serialize( $args ) );

	if ( ! isset( $crons[ $timestamp ][ $hook ][ $key ] ) ) {
		return $wp_error ? new WP_Error( 'could_not_set', 'The cron event list could not be saved.' ) : false;
	}

	unset( $crons[ $timestamp ][ $hook ][ $key ] );
	if ( empty( $crons[ $timestamp ][ $hook ] ) ) {
		unset( $crons[ $timestamp ][ $hook ] );
	}
	if ( empty( $crons[ $timestamp ] ) ) {
		unset( $crons[ $timestamp ] );
	}

	WpState::$options['cron'] = $crons;
	return true;
}

/**
 * Emulates wp_reschedule_event().
 */
function wp_reschedule_event( int $timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false ) {
	if ( null !== WpState::$reschedule_result ) {
		return WpState::$reschedule_result;
	}

	$interval = WpState::$schedules[ $recurrence ]['interval'] ?? 0;
	if ( 0 === $interval ) {
		return $wp_error ? new WP_Error( 'invalid_schedule', 'Event schedule does not exist.' ) : false;
	}

	$now  = time();
	$next = $timestamp >= $now ? $now + $interval : $timestamp + (int) ( ceil( ( $now - $timestamp ) / $interval ) * $interval );

	$crons = get_option( 'cron', array() );
	$crons[ $next ][ $hook ][ md5( serialize( $args ) ) ] = array(
		'schedule' => $recurrence,
		'args'     => $args,
		'interval' => $interval,
	);
	ksort( $crons );
	WpState::$options['cron'] = $crons;

	return true;
}

// Admin page helpers.

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function _n( string $single, string $plural, int $number, string $domain = 'default' ): string {
	return 1 === $number ? $single : $plural;
}

function esc_html( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_attr( $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ): string {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( string $text, string $domain = 'default' ): string {
	return esc_html( $text );
}

function current_user_can( string $capability ): bool {
	return WpState::$can;
}

function wp_die( $message = '' ): void {
	throw new RuntimeException( 'wp_die: ' . $message );
}

function wp_nonce_field( string $action = '-1', string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
	$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( $action ) . '" />';
	if ( $display ) {
		echo $field;
	}
	return $field;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return WpState::$nonce_valid && 'nonce-' . $action === $nonce ? 1 : false;
}

function sanitize_text_field( $text ): string {
	return trim( strip_tags( (string) $text ) );
}

function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true ): void {
	echo '<button type="submit" class="button ' . esc_attr( (string) $type ) . '">' . esc_html( (string) $text ) . '</button>';
}

function add_management_page( string $page_title, string $menu_title, string $capability, string $slug, $callback = '' ) {
	WpState::$menu_pages[] = compact( 'page_title', 'menu_title', 'capability', 'slug', 'callback' );
	return 'tools_page_' . $slug;
}
