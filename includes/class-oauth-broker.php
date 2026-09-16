<?php
/**
 * Strict server-to-server client for the ShootCal Instagram OAuth broker.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class OAuth_Broker {

	private const BASE_URL       = 'https://api.shootcal.com/v1/public/wordpress/instagram';
	private const MAX_BODY_BYTES = 65536;

	/**
	 * @return array{authorizationUrl:string}|\WP_Error
	 */
	public function start( string $callback_url, string $return_state, string $code_challenge ) {
		if ( ! self::valid_callback_url( $callback_url )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $return_state )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $code_challenge ) ) {
			return self::error( 'shootcal_instagram_broker_request', __( 'WordPress could not create a safe Instagram connection request.', 'shootcal-social-feed' ) );
		}

		$response = $this->post(
			'/start',
			array(
				'callbackUrl'  => $callback_url,
				'codeChallenge' => $code_challenge,
				'pluginVersion' => VERSION,
				'returnState'   => $return_state,
				// Use WordPress Address rather than the public Home URL so a
				// subdirectory installation has the same origin and path as admin-post.php.
				'siteUrl'       => site_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		if ( ! self::exact_keys( $response, array( 'authorizationUrl' ) )
			|| ! is_string( $response['authorizationUrl'] )
			|| ! self::valid_authorization_url( $response['authorizationUrl'] ) ) {
			return self::error( 'shootcal_instagram_broker_response', __( 'ShootCal returned an unsafe Instagram authorization link.', 'shootcal-social-feed' ) );
		}

		return array( 'authorizationUrl' => $response['authorizationUrl'] );
	}

	/**
	 * @return array{status:string,account?:array{id:string,username:string},accessToken?:string,choices?:array<int,array{id:string,pageName:string,username:?string}>}|\WP_Error
	 */
	public function redeem( string $handoff, string $verifier, ?string $candidate = null ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $handoff )
			|| 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $verifier )
			|| ( null !== $candidate && 1 !== preg_match( '/^[A-Za-z0-9_-]{22}$/', $candidate ) ) ) {
			return self::error( 'shootcal_instagram_broker_request', __( 'The Instagram connection result was invalid or expired.', 'shootcal-social-feed' ) );
		}
		$body = array(
			'handoff' => $handoff,
			'verifier' => $verifier,
		);
		if ( null !== $candidate ) {
			$body['candidate'] = $candidate;
		}
		$response = $this->post( '/redeem', $body );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( self::exact_keys( $response, array( 'accessToken', 'account', 'status' ) )
			&& 'connected' === $response['status']
			&& is_string( $response['accessToken'] )
			&& strlen( $response['accessToken'] ) >= 20
			&& strlen( $response['accessToken'] ) <= 4096
			&& 1 !== preg_match( '/[\x00-\x20\x7F]/', $response['accessToken'] )
			&& self::valid_account( $response['account'] ) ) {
			return array(
				'status'      => 'connected',
				'account'     => $response['account'],
				'accessToken' => $response['accessToken'],
			);
		}

		if ( self::exact_keys( $response, array( 'choices', 'status' ) )
			&& 'selecting' === $response['status']
			&& is_array( $response['choices'] )
			&& self::is_list( $response['choices'] )
			&& count( $response['choices'] ) >= 2
			&& count( $response['choices'] ) <= 100 ) {
			$choices = array();
			$seen    = array();
			foreach ( $response['choices'] as $choice ) {
				if ( ! is_array( $choice ) || ! self::exact_keys( $choice, array( 'id', 'pageName', 'username' ) )
					|| ! is_string( $choice['id'] ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{22}$/', $choice['id'] )
					|| isset( $seen[ $choice['id'] ] ) || ! is_string( $choice['pageName'] )
					|| '' === trim( $choice['pageName'] ) || strlen( $choice['pageName'] ) > 191
					|| ( null !== $choice['username'] && ( ! is_string( $choice['username'] ) || ! self::valid_username( $choice['username'] ) ) ) ) {
					return self::error( 'shootcal_instagram_broker_response', __( 'ShootCal returned an invalid Instagram account list.', 'shootcal-social-feed' ) );
				}
				$seen[ $choice['id'] ] = true;
				$choices[]             = $choice;
			}

			return array( 'status' => 'selecting', 'choices' => $choices );
		}

		return self::error( 'shootcal_instagram_broker_response', __( 'ShootCal returned an unreadable Instagram connection result.', 'shootcal-social-feed' ) );
	}

	/** @return array<string,mixed>|\WP_Error */
	private function post( string $path, array $body ) {
		$response = wp_remote_post(
			self::BASE_URL . $path,
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'headers'             => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'User-Agent'   => 'ShootCal-Instagram-Feed/' . VERSION,
				),
				'body'                => wp_json_encode( $body ),
				'limit_response_size' => self::MAX_BODY_BYTES,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::error( 'shootcal_instagram_broker_unavailable', __( 'ShootCal could not be reached. Try connecting again.', 'shootcal-social-feed' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			return self::error( 'shootcal_instagram_broker_response', __( 'ShootCal returned an oversized response.', 'shootcal-social-feed' ) );
		}
		$data = json_decode( $raw, true );
		if ( $status < 200 || $status >= 300 || ! is_array( $data ) || self::is_list( $data ) ) {
			return self::error( 'shootcal_instagram_broker_failed', __( 'Instagram could not be connected. Try again.', 'shootcal-social-feed' ) );
		}

		return $data;
	}

	private static function valid_callback_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		$admin = wp_parse_url( admin_url() );
		$admin_path = is_array( $admin ) ? rtrim( (string) ( $admin['path'] ?? '' ), '/' ) : '';
		return is_array( $parts )
			&& is_array( $admin )
			&& 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) )
			&& '' !== (string) ( $parts['host'] ?? '' )
			&& strtolower( (string) $parts['host'] ) === strtolower( (string) ( $admin['host'] ?? '' ) )
			&& $admin_path . '/admin-post.php' === (string) ( $parts['path'] ?? '' )
			&& 'action=shootcal_instagram_oauth_callback' === (string) ( $parts['query'] ?? '' )
			&& ! isset( $parts['fragment'], $parts['user'], $parts['pass'], $parts['port'] );
	}

	private static function valid_authorization_url( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| 'www.facebook.com' !== strtolower( (string) ( $parts['host'] ?? '' ) )
			|| 1 !== preg_match( '#^/v[0-9]{1,2}\.[0-9]{1,2}/dialog/oauth$#', (string) ( $parts['path'] ?? '' ) )
			|| isset( $parts['fragment'], $parts['user'], $parts['pass'], $parts['port'] ) ) {
			return false;
		}
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( 6 !== count( explode( '&', (string) ( $parts['query'] ?? '' ) ) ) ) {
			return false;
		}
		$keys = array_keys( $query );
		sort( $keys );
		return array( 'client_id', 'config_id', 'override_default_response_type', 'redirect_uri', 'response_type', 'state' ) === $keys
			&& is_string( $query['client_id'] ?? null ) && 1 === preg_match( '/^[0-9]{5,64}$/', $query['client_id'] )
			&& is_string( $query['config_id'] ?? null ) && 1 === preg_match( '/^[0-9]{5,64}$/', $query['config_id'] )
			&& 'https://api.shootcal.com/v1/public/wordpress/instagram/callback' === ( $query['redirect_uri'] ?? null )
			&& 'code' === ( $query['response_type'] ?? null )
			&& 'true' === ( $query['override_default_response_type'] ?? null )
			&& is_string( $query['state'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_-]{43}$/', $query['state'] );
	}

	private static function valid_account( mixed $account ): bool {
		return is_array( $account ) && self::exact_keys( $account, array( 'id', 'username' ) )
			&& is_string( $account['id'] ) && 1 === preg_match( '/^[0-9]{1,191}$/', $account['id'] )
			&& is_string( $account['username'] )
			&& ( '' === $account['username'] || self::valid_username( $account['username'] ) );
	}

	private static function valid_username( string $username ): bool {
		return strlen( $username ) >= 1 && strlen( $username ) <= 30
			&& strtolower( $username ) === $username
			&& 1 === preg_match( '/^[a-z0-9_](?:[a-z0-9._]{0,28}[a-z0-9_])?$/', $username )
			&& ! str_contains( $username, '..' );
	}

	/**
	 * PHP 8.0-compatible equivalent of array_is_list().
	 *
	 * @param array<mixed> $value Candidate list.
	 */
	private static function is_list( array $value ): bool {
		return array_values( $value ) === $value;
	}

	/** @param array<string,mixed> $value @param array<int,string> $expected */
	private static function exact_keys( array $value, array $expected ): bool {
		$keys = array_keys( $value );
		sort( $keys );
		sort( $expected );

		return $keys === $expected;
	}

	private static function error( string $code, string $message ): \WP_Error {
		return new \WP_Error( $code, $message );
	}
}
