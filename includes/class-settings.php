<?php
/**
 * Settings, connection health, and manual feed refresh.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Settings {

	private const PAGE_SLUG = 'shootcal-instagram-feed';
	private const RESULT_NONCE_ACTION = 'shootcal_instagram_result';
	private const OAUTH_SECONDS = 600;
	private const TOKEN_PATTERN = '/^[A-Za-z0-9_-]{43}$/';

	private OAuth_Broker $broker;

	public function __construct( private Feed_Store $store, ?OAuth_Broker $broker = null ) {
		$this->broker = $broker ?? new OAuth_Broker();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_shootcal_instagram_refresh', array( $this, 'handle_refresh' ) );
		add_action( 'admin_post_shootcal_instagram_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_shootcal_instagram_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_shootcal_instagram_oauth_callback', array( $this, 'handle_oauth_callback' ) );
		add_action( 'admin_post_shootcal_instagram_oauth_select', array( $this, 'handle_oauth_select' ) );
	}

	public function add_page(): void {
		add_options_page(
			__( 'ShootCal Instagram Feed', 'shootcal-instagram-feed' ),
			__( 'ShootCal Instagram Feed', 'shootcal-instagram-feed' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings(): void {
		register_setting(
			'shootcal_instagram_feed_group',
			OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => Config::defaults(),
			)
		);
	}

	/**
	 * @param mixed $input Submitted settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( $input ): array {
		$current = Config::get();
		$input   = is_array( $input ) ? $input : array();
		$submitted_account_id = isset( $input['instagram_account_id'] ) ? trim( (string) $input['instagram_account_id'] ) : '';
		$account_id           = $submitted_account_id;
		if ( '' !== $submitted_account_id && 1 !== preg_match( '/^\d+$/', $submitted_account_id ) ) {
			$account_id = (string) $current['instagram_account_id'];
			add_settings_error( OPTION_KEY, 'account-id-error', __( 'The Instagram business account ID must contain only digits.', 'shootcal-instagram-feed' ), 'error' );
		}

		$submitted_hashtag = isset( $input['default_hashtag'] ) ? trim( (string) $input['default_hashtag'] ) : '';
		$default_hashtag   = Hashtag_Filter::normalize( $submitted_hashtag );
		if ( '' !== $submitted_hashtag && '' === $default_hashtag ) {
			$default_hashtag = (string) $current['default_hashtag'];
			add_settings_error( OPTION_KEY, 'hashtag-error', __( 'The default hashtag may contain only letters, numbers, or underscores, with one optional leading #.', 'shootcal-instagram-feed' ), 'error' );
		}

		$output = array(
			'access_token'         => $current['access_token'],
			'instagram_account_id' => $account_id,
			'token_updated_at'     => $current['token_updated_at'],
			'default_hashtag'      => $default_hashtag,
			'display_limit'        => max( 1, min( 30, isset( $input['display_limit'] ) ? (int) $input['display_limit'] : 9 ) ),
			'columns'              => max( 1, min( 6, isset( $input['columns'] ) ? (int) $input['columns'] : 3 ) ),
			'scan_limit'           => max( 10, min( 100, isset( $input['scan_limit'] ) ? (int) $input['scan_limit'] : 60 ) ),
		);

		$new_token = isset( $input['new_access_token'] ) ? preg_replace( '/\s+/', '', trim( (string) $input['new_access_token'] ) ) : '';
		$new_token = is_string( $new_token ) ? $new_token : '';
		if ( '' !== $new_token ) {
			$encrypted = Token_Cipher::encrypt( $new_token );
			if ( is_wp_error( $encrypted ) ) {
				add_settings_error( OPTION_KEY, 'token-error', $encrypted->get_error_message(), 'error' );
			} elseif ( strlen( $new_token ) > 4096 ) {
				add_settings_error( OPTION_KEY, 'token-error', __( 'The access token was too long.', 'shootcal-instagram-feed' ), 'error' );
			} else {
				$output['access_token']     = $encrypted;
				$output['token_updated_at'] = time();
				add_settings_error( OPTION_KEY, 'token-saved', __( 'Access token saved securely. Use Refresh feed now to test it.', 'shootcal-instagram-feed' ), 'success' );
			}
		}

		return $output;
	}

	public function handle_refresh(): void {
		$this->authorize_action( 'shootcal_instagram_refresh' );
		$result = $this->store->refresh();
		$url    = add_query_arg(
			array(
				'shootcal_instagram_result'       => is_wp_error( $result ) ? 'error' : 'success',
				'shootcal_instagram_result_nonce' => wp_create_nonce( self::RESULT_NONCE_ACTION ),
			),
			$this->settings_url()
		);

		wp_safe_redirect( $url );
		exit;
	}

	public function handle_disconnect(): void {
		$this->authorize_action( 'shootcal_instagram_disconnect' );
		Config::clear_token();
		delete_option( CACHE_KEY );
		delete_option( STATUS_KEY );
		delete_option( LOCK_KEY );
		delete_option( OAUTH_KEY );

		wp_safe_redirect(
			add_query_arg(
				array(
					'shootcal_instagram_result'       => 'disconnected',
					'shootcal_instagram_result_nonce' => wp_create_nonce( self::RESULT_NONCE_ACTION ),
				),
				$this->settings_url()
			)
		);
		exit;
	}

	public function handle_connect(): void {
		$this->authorize_action( 'shootcal_instagram_connect' );
		try {
			$verifier = self::random_token();
			$state    = self::random_token();
		} catch ( \Throwable ) {
			$this->redirect_result( 'oauth_error' );
		}
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$callback  = admin_url( 'admin-post.php?action=shootcal_instagram_oauth_callback' );
		$result    = $this->broker->start( $callback, $state, $challenge );
		if ( is_wp_error( $result ) ) {
			$this->redirect_result( 'oauth_error' );
		}
		$encrypted = Token_Cipher::encrypt( $verifier );
		self::wipe( $verifier );
		if ( is_wp_error( $encrypted ) ) {
			$this->redirect_result( 'oauth_error' );
		}
		$saved = update_option(
			OAUTH_KEY,
			array(
				'state_hash'          => hash( 'sha256', $state ),
				'verifier_ciphertext' => $encrypted,
				'user_id'             => get_current_user_id(),
				'expires_at'          => time() + self::OAUTH_SECONDS,
				'handoff_ciphertext'  => '',
				'choices'             => array(),
			),
			false
		);
		if ( ! $saved ) {
			$this->redirect_result( 'oauth_error' );
		}

		// OAuth_Broker has already constrained this to Meta's exact HTTPS dialog.
		wp_redirect( $result['authorizationUrl'], 302, 'ShootCal Instagram Feed' ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	public function handle_oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sign in to WordPress as an administrator to finish connecting Instagram.', 'shootcal-instagram-feed' ), '', array( 'response' => 403 ) );
		}
		$state   = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$context = $this->oauth_context( $state );
		if ( null === $context ) {
			$this->redirect_result( 'oauth_expired' );
		}
		if ( isset( $_GET['error'] ) ) {
			delete_option( OAUTH_KEY );
			$this->redirect_result( 'oauth_denied' );
		}
		$handoff = isset( $_GET['handoff'] ) ? sanitize_text_field( wp_unslash( $_GET['handoff'] ) ) : '';
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $handoff ) ) {
			delete_option( OAUTH_KEY );
			$this->redirect_result( 'oauth_error' );
		}
		$verifier = Token_Cipher::decrypt( (string) $context['verifier_ciphertext'] );
		if ( is_wp_error( $verifier ) ) {
			delete_option( OAUTH_KEY );
			$this->redirect_result( 'oauth_error' );
		}
		$result = $this->broker->redeem( $handoff, $verifier );
		self::wipe( $verifier );
		if ( is_wp_error( $result ) ) {
			$this->redirect_result( 'oauth_error' );
		}
		if ( 'selecting' === $result['status'] ) {
			$handoff_ciphertext = Token_Cipher::encrypt( $handoff );
			self::wipe( $handoff );
			if ( is_wp_error( $handoff_ciphertext ) ) {
				delete_option( OAUTH_KEY );
				$this->redirect_result( 'oauth_error' );
			}
			$context['handoff_ciphertext'] = $handoff_ciphertext;
			$context['choices']            = $result['choices'];
			if ( ! update_option( OAUTH_KEY, $context, false ) ) {
				delete_option( OAUTH_KEY );
				$this->redirect_result( 'oauth_error' );
			}
			$this->redirect_result( 'oauth_select', array( 'oauth_state' => $state ) );
		}
		self::wipe( $handoff );
		$this->finish_oauth_connection( $result );
	}

	public function handle_oauth_select(): void {
		$this->authorize_action( 'shootcal_instagram_oauth_select' );
		$state     = isset( $_POST['oauth_state'] ) ? sanitize_text_field( wp_unslash( $_POST['oauth_state'] ) ) : '';
		$candidate = isset( $_POST['candidate'] ) ? sanitize_text_field( wp_unslash( $_POST['candidate'] ) ) : '';
		$context   = $this->oauth_context( $state );
		if ( null === $context || 1 !== preg_match( '/^[A-Za-z0-9_-]{22}$/', $candidate )
			|| ! in_array( $candidate, array_column( $context['choices'], 'id' ), true ) ) {
			delete_option( OAUTH_KEY );
			$this->redirect_result( 'oauth_expired' );
		}
		$verifier = Token_Cipher::decrypt( (string) $context['verifier_ciphertext'] );
		$handoff = Token_Cipher::decrypt( (string) $context['handoff_ciphertext'] );
		if ( is_wp_error( $verifier ) || is_wp_error( $handoff ) ) {
			delete_option( OAUTH_KEY );
			$this->redirect_result( 'oauth_error' );
		}
		$result = $this->broker->redeem( $handoff, $verifier, $candidate );
		self::wipe( $handoff );
		self::wipe( $verifier );
		if ( is_wp_error( $result ) || 'connected' !== ( $result['status'] ?? '' ) ) {
			$this->redirect_result( 'oauth_error' );
		}
		$this->finish_oauth_connection( $result );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options = Config::get();
		$status  = Feed_Store::status();
		$cache   = Feed_Store::cache_for_account( (string) $options['instagram_account_id'] );
		$username = isset( $cache['account']['username'] ) && is_string( $cache['account']['username'] ) ? $cache['account']['username'] : '';
		$result = '';
		if ( isset( $_GET['shootcal_instagram_result'], $_GET['shootcal_instagram_result_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['shootcal_instagram_result_nonce'] ) );
			if ( wp_verify_nonce( $nonce, self::RESULT_NONCE_ACTION ) ) {
				$result = sanitize_key( wp_unslash( $_GET['shootcal_instagram_result'] ) );
			}
		}

		if ( 'success' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instagram connection succeeded and the cached feed was refreshed.', 'shootcal-instagram-feed' ) . '</p></div>';
		} elseif ( 'error' === $result ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The Instagram refresh failed. The last successful cache was preserved; see Connection status below.', 'shootcal-instagram-feed' ) . '</p></div>';
		} elseif ( 'disconnected' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instagram was disconnected and its cached feed was removed.', 'shootcal-instagram-feed' ) . '</p></div>';
		} elseif ( 'oauth_connected' === $result ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Instagram connected successfully. The first cached feed is ready.', 'shootcal-instagram-feed' ) . '</p></div>';
		} elseif ( 'oauth_connected_refresh_error' === $result ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Instagram connected, but the first feed refresh failed. Use Refresh feed now to retry.', 'shootcal-instagram-feed' ) . '</p></div>';
		} elseif ( 'oauth_denied' === $result ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html__( 'Instagram connection was canceled. Your previous connection was not changed.', 'shootcal-instagram-feed' ) . '</p></div>';
		} elseif ( 'oauth_expired' === $result ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The Instagram connection request expired. Start again.', 'shootcal-instagram-feed' ) . '</p></div>';
		} elseif ( 'oauth_error' === $result ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Instagram could not be connected. Your previous connection was not changed.', 'shootcal-instagram-feed' ) . '</p></div>';
		}
		$selection = 'oauth_select' === $result && isset( $_GET['oauth_state'] )
			? $this->oauth_context( sanitize_text_field( wp_unslash( $_GET['oauth_state'] ) ) ) : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'ShootCal Instagram Feed', 'shootcal-instagram-feed' ); ?></h1>
			<p style="max-width:55em;"><?php esc_html_e( 'A small, server-cached feed for one Instagram Business or Creator account. Page visitors never trigger live Instagram API calls.', 'shootcal-instagram-feed' ); ?></p>

			<h2><?php esc_html_e( 'Connection status', 'shootcal-instagram-feed' ); ?></h2>
			<table class="widefat striped" style="max-width:55em;">
				<tbody>
					<tr><th scope="row" style="width:14em;"><?php esc_html_e( 'Access token', 'shootcal-instagram-feed' ); ?></th><td><?php echo Config::has_token() ? esc_html__( 'Stored securely', 'shootcal-instagram-feed' ) : esc_html__( 'Not configured', 'shootcal-instagram-feed' ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Instagram account ID', 'shootcal-instagram-feed' ); ?></th><td><?php echo ! empty( $options['instagram_account_id'] ) ? esc_html( (string) $options['instagram_account_id'] ) : '&mdash;'; ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Account', 'shootcal-instagram-feed' ); ?></th><td><?php echo '' !== $username ? esc_html( '@' . $username ) : '&mdash;'; ?></td></tr>
						<tr><th scope="row"><?php esc_html_e( 'Last successful refresh', 'shootcal-instagram-feed' ); ?></th><td><?php echo ! empty( $cache ) && ! empty( $status['last_success'] ) ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['last_success'] ) ) : '&mdash;'; ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Cached posts', 'shootcal-instagram-feed' ); ?></th><td><?php echo esc_html( (string) count( isset( $cache['items'] ) && is_array( $cache['items'] ) ? $cache['items'] : array() ) ); ?></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Last error', 'shootcal-instagram-feed' ); ?></th><td><?php echo ! empty( $status['last_error'] ) ? esc_html( (string) $status['last_error'] ) : '&mdash;'; ?></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Connect Instagram', 'shootcal-instagram-feed' ); ?></h2>
			<p style="max-width:55em;"><?php esc_html_e( 'Use your Facebook login to choose a linked professional Instagram account. ShootCal handles the authorization, then this WordPress site stores the resulting token locally in encrypted form.', 'shootcal-instagram-feed' ); ?></p>
			<?php if ( is_array( $selection ) && ! empty( $selection['choices'] ) ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="max-width:55em;">
					<input type="hidden" name="action" value="shootcal_instagram_oauth_select" />
					<input type="hidden" name="oauth_state" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET['oauth_state'] ) ) ); ?>" />
					<?php wp_nonce_field( 'shootcal_instagram_oauth_select' ); ?>
					<fieldset>
						<legend class="screen-reader-text"><?php esc_html_e( 'Choose an Instagram account', 'shootcal-instagram-feed' ); ?></legend>
						<?php foreach ( $selection['choices'] as $index => $choice ) : ?>
							<p><label><input type="radio" name="candidate" value="<?php echo esc_attr( (string) $choice['id'] ); ?>" <?php checked( 0, $index ); ?> required /> <strong><?php echo esc_html( (string) $choice['pageName'] ); ?></strong><?php echo null !== $choice['username'] ? ' — ' . esc_html( '@' . (string) $choice['username'] ) : ''; ?></label></p>
						<?php endforeach; ?>
					</fieldset>
					<?php submit_button( __( 'Use this Instagram account', 'shootcal-instagram-feed' ), 'primary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="shootcal_instagram_connect" />
					<?php wp_nonce_field( 'shootcal_instagram_connect' ); ?>
					<?php submit_button( Config::has_token() ? __( 'Reconnect with Facebook', 'shootcal-instagram-feed' ) : __( 'Connect with Facebook', 'shootcal-instagram-feed' ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<details style="max-width:55em;margin-top:1.5em;">
				<summary><strong><?php esc_html_e( 'Advanced: enter a token manually', 'shootcal-instagram-feed' ); ?></strong></summary>
			<form method="post" action="options.php">
				<?php settings_fields( 'shootcal_instagram_feed_group' ); ?>
				<h2><?php esc_html_e( 'Instagram connection', 'shootcal-instagram-feed' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="shootcal-instagram-account-id"><?php esc_html_e( 'Instagram business account ID', 'shootcal-instagram-feed' ); ?></label></th>
						<td>
							<input type="text" inputmode="numeric" pattern="[0-9]+" name="<?php echo esc_attr( OPTION_KEY ); ?>[instagram_account_id]" id="shootcal-instagram-account-id" value="<?php echo esc_attr( (string) $options['instagram_account_id'] ); ?>" class="regular-text" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'The numeric Instagram business account ID linked to the Facebook Page.', 'shootcal-instagram-feed' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="shootcal-instagram-token"><?php esc_html_e( 'Long-lived Page access token', 'shootcal-instagram-feed' ); ?></label></th>
						<td>
							<input type="password" name="<?php echo esc_attr( OPTION_KEY ); ?>[new_access_token]" id="shootcal-instagram-token" value="" class="regular-text" autocomplete="new-password" spellcheck="false" />
							<p class="description"><?php esc_html_e( 'Leave blank to keep the stored token. Use a Page token with instagram_basic and pages_show_list. It is encrypted with this WordPress installation’s authentication salt and is never sent to page visitors.', 'shootcal-instagram-feed' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Feed defaults', 'shootcal-instagram-feed' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="shootcal-instagram-hashtag"><?php esc_html_e( 'Default hashtag', 'shootcal-instagram-feed' ); ?></label></th>
						<td><input type="text" name="<?php echo esc_attr( OPTION_KEY ); ?>[default_hashtag]" id="shootcal-instagram-hashtag" value="<?php echo esc_attr( (string) $options['default_hashtag'] ); ?>" class="regular-text" placeholder="weddings" /><p class="description"><?php esc_html_e( 'Optional. The leading # is not required. Matching is exact and case-insensitive against captions from your own account.', 'shootcal-instagram-feed' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="shootcal-instagram-limit"><?php esc_html_e( 'Posts to display', 'shootcal-instagram-feed' ); ?></label></th>
						<td><input type="number" name="<?php echo esc_attr( OPTION_KEY ); ?>[display_limit]" id="shootcal-instagram-limit" value="<?php echo esc_attr( (string) $options['display_limit'] ); ?>" min="1" max="30" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="shootcal-instagram-columns"><?php esc_html_e( 'Desktop columns', 'shootcal-instagram-feed' ); ?></label></th>
						<td><input type="number" name="<?php echo esc_attr( OPTION_KEY ); ?>[columns]" id="shootcal-instagram-columns" value="<?php echo esc_attr( (string) $options['columns'] ); ?>" min="1" max="6" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="shootcal-instagram-scan"><?php esc_html_e( 'Recent posts to scan', 'shootcal-instagram-feed' ); ?></label></th>
						<td><input type="number" name="<?php echo esc_attr( OPTION_KEY ); ?>[scan_limit]" id="shootcal-instagram-scan" value="<?php echo esc_attr( (string) $options['scan_limit'] ); ?>" min="10" max="100" class="small-text" /><p class="description"><?php esc_html_e( 'A larger number lets a less-common hashtag find older posts. The feed refreshes every six hours.', 'shootcal-instagram-feed' ); ?></p></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save settings', 'shootcal-instagram-feed' ) ); ?>
			</form>
			</details>

			<h2><?php esc_html_e( 'Refresh and test', 'shootcal-instagram-feed' ); ?></h2>
			<p><code>[shootcal_instagram_feed]</code></p>
			<p><code>[shootcal_instagram_feed hashtag="weddings" limit="9" columns="3"]</code></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:8px;">
				<input type="hidden" name="action" value="shootcal_instagram_refresh" />
				<?php wp_nonce_field( 'shootcal_instagram_refresh' ); ?>
				<?php submit_button( __( 'Refresh feed now', 'shootcal-instagram-feed' ), 'secondary', 'submit', false, Config::has_token() ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
			<?php if ( Config::has_token() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
					<input type="hidden" name="action" value="shootcal_instagram_disconnect" />
					<?php wp_nonce_field( 'shootcal_instagram_disconnect' ); ?>
					<?php submit_button( __( 'Disconnect and clear cache', 'shootcal-instagram-feed' ), 'delete', 'submit', false, array( 'onclick' => 'return window.confirm(' . wp_json_encode( __( 'Disconnect Instagram and remove the cached feed?', 'shootcal-instagram-feed' ) ) . ');' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	private function authorize_action( string $nonce_action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You are not allowed to manage this Instagram feed.', 'shootcal-instagram-feed' ),
				'',
				array( 'response' => 403 )
			);
		}

		check_admin_referer( $nonce_action );
	}

	private function settings_url(): string {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/** @return array<string,mixed>|null */
	private function oauth_context( string $state ): ?array {
		if ( 1 !== preg_match( self::TOKEN_PATTERN, $state ) ) {
			return null;
		}
		$value = get_option( OAUTH_KEY, array() );
		if ( ! is_array( $value ) || (int) ( $value['user_id'] ?? 0 ) !== get_current_user_id()
			|| (int) ( $value['expires_at'] ?? 0 ) < time()
			|| ! is_string( $value['state_hash'] ?? null )
			|| ! hash_equals( $value['state_hash'], hash( 'sha256', $state ) )
			|| ! is_string( $value['verifier_ciphertext'] ?? null )
			|| ! is_string( $value['handoff_ciphertext'] ?? null )
			|| ! self::valid_choices( $value['choices'] ?? null ) ) {
			return null;
		}

		return $value;
	}

	/** @param array<string,mixed> $result */
	private function finish_oauth_connection( array $result ): void {
		$token = (string) ( $result['accessToken'] ?? '' );
		$stored = Config::store_connection(
			$token,
			(string) ( $result['account']['id'] ?? '' )
		);
		self::wipe( $token );
		if ( isset( $result['accessToken'] ) && is_string( $result['accessToken'] ) ) {
			self::wipe( $result['accessToken'] );
		}
		if ( is_wp_error( $stored ) ) {
			$this->redirect_result( 'oauth_error' );
		}
		delete_option( OAUTH_KEY );
		delete_option( CACHE_KEY );
		delete_option( STATUS_KEY );
		delete_option( LOCK_KEY );
		$refreshed = $this->store->refresh();
		$this->redirect_result( is_wp_error( $refreshed ) ? 'oauth_connected_refresh_error' : 'oauth_connected' );
	}

	/** @param array<string,string> $extra */
	private function redirect_result( string $result, array $extra = array() ): void {
		$url = add_query_arg(
			array_merge(
				$extra,
				array(
					'shootcal_instagram_result'       => $result,
					'shootcal_instagram_result_nonce' => wp_create_nonce( self::RESULT_NONCE_ACTION ),
				)
			),
			$this->settings_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	private static function random_token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	private static function valid_choices( mixed $value ): bool {
		if ( ! is_array( $value ) || array_values( $value ) !== $value || count( $value ) > 100 ) {
			return false;
		}
		$seen = array();
		foreach ( $value as $choice ) {
			if ( ! is_array( $choice ) || array_keys( $choice ) !== array( 'id', 'pageName', 'username' )
				|| ! is_string( $choice['id'] ) || 1 !== preg_match( '/^[A-Za-z0-9_-]{22}$/', $choice['id'] )
				|| isset( $seen[ $choice['id'] ] ) || ! is_string( $choice['pageName'] )
				|| '' === trim( $choice['pageName'] ) || strlen( $choice['pageName'] ) > 191
				|| ( null !== $choice['username'] && ! is_string( $choice['username'] ) ) ) {
				return false;
			}
			$seen[ $choice['id'] ] = true;
		}

		return true;
	}

	private static function wipe( string &$value ): void {
		if ( function_exists( 'sodium_memzero' ) ) {
			sodium_memzero( $value );
		}
	}
}
