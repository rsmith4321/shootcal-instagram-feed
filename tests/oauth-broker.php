<?php
/**
 * Dependency-free contract test for the ShootCal OAuth broker client.
 */

declare( strict_types=1 );

define( 'ABSPATH', __DIR__ . '/' );
define( 'ShootCalInstagramFeed\\VERSION', '0.2.0' );

class WP_Error {
	public function __construct( public string $code, public string $message ) {}
}

function is_wp_error( mixed $value ): bool {
	return $value instanceof WP_Error;
}

function __( string $message, string $domain = '' ): string {
	return $message;
}

function wp_parse_url( string $url, int $component = -1 ): array|string|int|null|false {
	return parse_url( $url, $component );
}

$wordpress_path = '';
function admin_url( string $path = '' ): string {
	global $wordpress_path;
	return 'https://example.com' . $wordpress_path . '/wp-admin/' . ltrim( $path, '/' );
}

function site_url( string $path = '' ): string {
	global $wordpress_path;
	return 'https://example.com' . $wordpress_path . '/' . ltrim( $path, '/' );
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value, JSON_UNESCAPED_SLASHES );
}

$broker_response = array();
$broker_request  = array();
function wp_remote_post( string $url, array $args ): array {
	global $broker_request, $broker_response;
	$broker_request = array( 'url' => $url, 'args' => $args );

	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( $broker_response, JSON_UNESCAPED_SLASHES ) );
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

require_once dirname( __DIR__ ) . '/includes/class-oauth-broker.php';

use ShootCalInstagramFeed\OAuth_Broker;

$token     = static fn( string $byte, int $bytes = 32 ): string => rtrim( strtr( base64_encode( str_repeat( $byte, $bytes ) ), '+/', '-_' ), '=' );
$state     = $token( 'S' );
$challenge = $token( 'C' );
$handoff   = $token( 'H' );
$verifier  = $token( 'V' );
$broker    = new OAuth_Broker();

$broker_response = array(
	'authorizationUrl' => 'https://www.facebook.com/v26.0/dialog/oauth?client_id=1234567890123456&redirect_uri=https%3A%2F%2Fapi.shootcal.com%2Fv1%2Fpublic%2Fwordpress%2Finstagram%2Fcallback&response_type=code&scope=instagram_basic%2Cpages_show_list&state=' . $state,
);
$started = $broker->start(
	'https://example.com/wp-admin/admin-post.php?action=shootcal_instagram_oauth_callback',
	$state,
	$challenge
);
if ( is_wp_error( $started ) || $started !== $broker_response ) {
	throw new RuntimeException( 'The exact safe authorization response was not accepted.' );
}
$body = json_decode( (string) $broker_request['args']['body'], true );
if ( $broker_request['url'] !== 'https://api.shootcal.com/v1/public/wordpress/instagram/start'
	|| array_keys( $body ) !== array( 'callbackUrl', 'codeChallenge', 'pluginVersion', 'returnState', 'siteUrl' )
	|| $body['codeChallenge'] !== $challenge || $body['returnState'] !== $state
	|| isset( $body['verifier'] ) ) {
	throw new RuntimeException( 'The start request exposed the verifier or changed the exact broker envelope.' );
}

$wordpress_path = '/wordpress';
$subdirectory_started = $broker->start(
	'https://example.com/wordpress/wp-admin/admin-post.php?action=shootcal_instagram_oauth_callback',
	$state,
	$challenge
);
$subdirectory_body = json_decode( (string) $broker_request['args']['body'], true );
if ( is_wp_error( $subdirectory_started )
	|| 'https://example.com/wordpress/' !== ( $subdirectory_body['siteUrl'] ?? '' ) ) {
	throw new RuntimeException( 'A valid WordPress subdirectory installation did not preserve its admin path.' );
}
$wordpress_path = '';

$unsafe = $broker_response;
$unsafe['authorizationUrl'] .= '&next=https%3A%2F%2Fevil.example';
$broker_response = $unsafe;
if ( ! is_wp_error( $broker->start(
	'https://example.com/wp-admin/admin-post.php?action=shootcal_instagram_oauth_callback',
	$state,
	$challenge
) ) ) {
	throw new RuntimeException( 'An authorization URL with an extra query key was accepted.' );
}
if ( ! is_wp_error( $broker->start(
	'https://evil.example/wp-admin/admin-post.php?action=shootcal_instagram_oauth_callback',
	$state,
	$challenge
) ) ) {
	throw new RuntimeException( 'A callback on another WordPress admin origin was accepted.' );
}

$choice_one = $token( '1', 16 );
$choice_two = $token( '2', 16 );
$broker_response = array(
	'status'  => 'selecting',
	'choices' => array(
		array( 'id' => $choice_one, 'pageName' => 'Studio One', 'username' => 'studio.one' ),
		array( 'id' => $choice_two, 'pageName' => 'Studio Two', 'username' => null ),
	),
);
$choices = $broker->redeem( $handoff, $verifier );
if ( is_wp_error( $choices ) || $choices !== $broker_response ) {
	throw new RuntimeException( 'The strict opaque account selection response was not accepted.' );
}
$redeem_body = json_decode( (string) $broker_request['args']['body'], true );
if ( $redeem_body !== array( 'handoff' => $handoff, 'verifier' => $verifier ) ) {
	throw new RuntimeException( 'The WordPress server did not prove the exact verifier during redemption.' );
}

$page_token = str_repeat( 'page-token-', 4 );
$broker_response = array(
	'status'      => 'connected',
	'account'     => array( 'id' => '17841400000000001', 'username' => '' ),
	'accessToken' => $page_token,
);
$connected = $broker->redeem( $handoff, $verifier, $choice_two );
if ( is_wp_error( $connected ) || $connected !== $broker_response ) {
	throw new RuntimeException( 'The exact connected account response was not accepted.' );
}
$selected_body = json_decode( (string) $broker_request['args']['body'], true );
if ( $selected_body !== array( 'handoff' => $handoff, 'verifier' => $verifier, 'candidate' => $choice_two ) ) {
	throw new RuntimeException( 'The opaque account selection changed the redemption envelope.' );
}

$broker_response['providerId'] = 'must-fail';
if ( ! is_wp_error( $broker->redeem( $handoff, $verifier, $choice_two ) ) ) {
	throw new RuntimeException( 'An unknown provider field crossed the broker response boundary.' );
}

echo "oauth-broker: ok\n";
