<?php
/**
 * Review, import, and explicitly switch saved Smash Balloon Instagram feeds.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Smash_Import_Admin {

	private const IMPORT_ACTION   = 'shootcal_instagram_smash_import';
	private const SWITCH_ACTION   = 'shootcal_instagram_smash_switch';
	private const ROLLBACK_ACTION = 'shootcal_instagram_smash_rollback';
	private const NOTICE_PREFIX   = 'shootcal_instagram_smash_notice_';

	public function register(): void {
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::SWITCH_ACTION, array( $this, 'handle_switch' ) );
		add_action( 'admin_post_' . self::ROLLBACK_ACTION, array( $this, 'handle_rollback' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_preview_assets' ) );
	}

	public function enqueue_preview_assets( string $hook ): void {
		// Another ShootCal plugin can own the parent menu and its hook prefix.
		if ( str_ends_with( $hook, '_page_shootcal-instagram-feed' ) && current_user_can( 'manage_options' ) ) {
			Assets::enqueue();
		}
	}

	public function handle_import(): void {
		self::authorize( self::IMPORT_ACTION );
		$choices = array();
		$raw     = isset( $_POST['choices'] ) && is_array( $_POST['choices'] ) ? wp_unslash( $_POST['choices'] ) : array();
		foreach ( $raw as $old_id => $choice ) {
			if ( ! is_scalar( $choice ) || 1 !== preg_match( '/^\d+$/D', (string) $old_id ) ) {
				self::redirect_result( new \WP_Error( 'invalid_choices', __( 'The selected feeds were invalid. Please review the import table and try again.', 'shootcal-social-feed' ) ) );
			}
			$choice = sanitize_text_field( (string) $choice );
			if ( '' === $choice || 'skip' === $choice ) {
				continue;
			}
			if ( 'new' !== $choice && 1 !== preg_match( '/^[1-9]\d*$/D', $choice ) ) {
				self::redirect_result( new \WP_Error( 'invalid_choices', __( 'Choose Create feed or an existing ShootCal feed for each selected row.', 'shootcal-social-feed' ) ) );
			}
			$choices[ (string) $old_id ] = $choice;
		}
		$result = Smash_Import::import( $choices, self::post_text( 'fingerprint' ), '1' === self::post_text( 'acknowledge' ) );
		self::redirect_result( $result, __( 'Selected feeds were imported. Review the cached previews below before switching the old shortcodes to ShootCal.', 'shootcal-social-feed' ) );
	}

	public function handle_switch(): void {
		self::authorize( self::SWITCH_ACTION, true );
		$active = Smash_Import::active_plugins();
		if ( ! empty( $active ) && '1' !== self::post_text( 'deactivate' ) ) {
			self::redirect_result( new \WP_Error( 'deactivate_not_confirmed', __( 'Review the previews and check the deactivation confirmation before switching.', 'shootcal-social-feed' ) ) );
		}
		if ( empty( $active ) && '1' !== self::post_text( 'preview_acknowledge' ) ) {
			self::redirect_result( new \WP_Error( 'preview_not_confirmed', __( 'Review the previews and confirm they show the feeds you want before enabling shortcode compatibility.', 'shootcal-social-feed' ) ) );
		}
		$fingerprint = self::post_text( 'fingerprint' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			self::redirect_result( new \WP_Error( 'invalid_preview', __( 'Reload and review the feed previews before switching.', 'shootcal-social-feed' ) ) );
		}
		$result = Smash_Import::switch( '1' === self::post_text( 'deactivate' ), $fingerprint );
		self::redirect_result( $result, __( 'Shortcode compatibility is enabled. Mapped Smash Balloon shortcodes now use ShootCal. Clear your page cache and check the affected pages.', 'shootcal-social-feed' ) );
	}

	public function handle_rollback(): void {
		self::authorize( self::ROLLBACK_ACTION, true );
		self::redirect_result( Smash_Import::rollback(), __( 'Shortcode compatibility was turned off. Smash Balloon plugins deactivated by this importer were reactivated. Imported ShootCal feeds and the original Smash Balloon data were preserved.', 'shootcal-social-feed' ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$catalog  = Smash_Import::catalog();
		$state    = Smash_Import::state();
		$feeds    = Feeds::all();
		$mappings = $state['mappings'];
		$enabled  = ! empty( $state['enabled'] );
		$active   = Smash_Import::active_plugins();
		?>
		<section id="shootcal-smash-import" style="max-width:75em;margin-top:2.5em;">
			<h2><?php esc_html_e( 'Import from Smash Balloon', 'shootcal-social-feed' ); ?></h2>
			<?php self::render_notice(); ?>
			<p><?php esc_html_e( 'Keep your existing Instagram shortcodes by matching saved Smash Balloon feeds to ShootCal feeds. First connect the same Instagram account to ShootCal, then import and review the previews. Importing alone does not change your live shortcodes or deactivate another plugin.', 'shootcal-social-feed' ); ?></p>
			<p><?php esc_html_e( 'ShootCal uses its own cached posts and grid styling, up to two phone columns, and links to Instagram. Headers, captions, likes, lightboxes, Load More, custom CSS, and custom button styles are not copied. Differences specific to each feed are listed below.', 'shootcal-social-feed' ); ?></p>
			<?php if ( $enabled ) : ?>
				<?php if ( ! empty( $active ) ) : ?>
					<div class="notice notice-warning inline">
						<p><strong><?php esc_html_e( 'Smash Balloon is active. ShootCal compatibility is paused.', 'shootcal-social-feed' ); ?></strong></p>
						<p><?php esc_html_e( 'ShootCal yields the old shortcodes to Smash Balloon while it is active. Your mappings are saved. Use Undo switch below, review the previews, and switch again to make ShootCal handle the old shortcodes.', 'shootcal-social-feed' ); ?></p>
					</div>
				<?php else : ?>
					<p><strong><?php esc_html_e( 'Shortcode compatibility is enabled.', 'shootcal-social-feed' ); ?></strong> <?php esc_html_e( 'Use Undo switch below before changing these mappings.', 'shootcal-social-feed' ); ?></p>
				<?php endif; ?>
			<?php elseif ( empty( $catalog ) ) : ?>
				<p><?php esc_html_e( 'No saved Smash Balloon Instagram feeds were found. Smash Balloon can stay inactive during import, but its saved feed data must still be present.', 'shootcal-social-feed' ); ?></p>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::IMPORT_ACTION ); ?>" />
					<input type="hidden" name="fingerprint" value="<?php echo esc_attr( Smash_Import::fingerprint( $catalog ) ); ?>" />
					<?php wp_nonce_field( self::IMPORT_ACTION ); ?>
					<div style="overflow-x:auto;">
						<table class="widefat striped">
							<thead><tr><th scope="col"><?php esc_html_e( 'Smash Balloon feed', 'shootcal-social-feed' ); ?></th><th scope="col"><?php esc_html_e( 'Compatibility', 'shootcal-social-feed' ); ?></th><th scope="col"><?php esc_html_e( 'Import as', 'shootcal-social-feed' ); ?></th></tr></thead>
							<tbody>
							<?php foreach ( $catalog as $old_id => $row ) : ?>
								<tr>
									<th scope="row" style="min-width:10em;">
										<strong><?php echo esc_html( (string) $row['name'] ); ?></strong><br />
										<code>[instagram-feed feed="<?php echo esc_html( (string) $old_id ); ?>"]</code>
										<?php if ( isset( $mappings[ $old_id ], $feeds[ $mappings[ $old_id ] ] ) ) : ?>
											<p class="description"><?php echo esc_html( sprintf( /* translators: %s: ShootCal feed name. */ __( 'Saved mapping: %s', 'shootcal-social-feed' ), (string) $feeds[ $mappings[ $old_id ] ]['name'] ) ); ?></p>
										<?php endif; ?>
									</th>
									<td>
										<p><?php echo ! empty( $row['can_import'] ) ? esc_html__( 'Can create a ShootCal feed.', 'shootcal-social-feed' ) : esc_html__( 'Automatic import is unavailable. Review the differences and choose an existing ShootCal feed if it is the output you want.', 'shootcal-social-feed' ); ?></p>
										<?php if ( ! empty( $row['messages'] ) ) : ?>
											<ul style="list-style:disc;padding-left:1.5em;">
												<?php foreach ( $row['messages'] as $message ) : ?>
													<li><?php echo esc_html( (string) $message ); ?></li>
												<?php endforeach; ?>
											</ul>
										<?php endif; ?>
									</td>
									<td style="min-width:13em;">
										<label class="screen-reader-text" for="shootcal-smash-choice-<?php echo esc_attr( (string) $old_id ); ?>"><?php echo esc_html( sprintf( /* translators: %s: source feed name. */ __( 'Replacement for %s', 'shootcal-social-feed' ), (string) $row['name'] ) ); ?></label>
										<select id="shootcal-smash-choice-<?php echo esc_attr( (string) $old_id ); ?>" name="choices[<?php echo esc_attr( (string) $old_id ); ?>]" style="max-width:100%;">
											<option value="skip"><?php esc_html_e( 'Skip', 'shootcal-social-feed' ); ?></option>
											<?php if ( ! empty( $row['can_import'] ) ) : ?>
												<option value="new"><?php esc_html_e( 'Create feed', 'shootcal-social-feed' ); ?></option>
											<?php endif; ?>
											<?php foreach ( $feeds as $feed_id => $feed ) : ?>
												<option value="<?php echo esc_attr( (string) $feed_id ); ?>"><?php echo esc_html( sprintf( /* translators: 1: feed name. 2: feed ID. */ __( 'Use %1$s (ShootCal #%2$d)', 'shootcal-social-feed' ), (string) $feed['name'], $feed_id ) ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					</div>
					<p><label><input type="checkbox" name="acknowledge" value="1" required /> <?php esc_html_e( 'I understand that the imported presentation may differ. Selecting an existing ShootCal feed uses that feed exactly, including its account and filters, instead of recreating unsupported Smash Balloon settings.', 'shootcal-social-feed' ); ?></label></p>
					<?php submit_button( __( 'Import selected feeds', 'shootcal-social-feed' ), 'secondary', 'submit', false ); ?>
					<p class="description"><?php esc_html_e( 'Skipped rows keep any existing mapping. This step only saves feed presets and mappings.', 'shootcal-social-feed' ); ?></p>
				</form>
			<?php endif; ?>
			<?php
			if ( ! empty( $mappings ) ) {
				self::render_previews( $catalog, $mappings, $feeds );
				self::render_switch( $enabled, $state );
			} elseif ( $enabled || ! empty( $state['deactivated'] ) ) {
				self::render_switch( $enabled, $state );
			}
			?>
		</section>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $catalog Saved source feeds.
	 * @param array<int, int>                 $mappings Old IDs and replacement IDs.
	 * @param array<int, array<string, mixed>> $feeds ShootCal presets.
	 */
	private static function render_previews( array $catalog, array $mappings, array $feeds ): void {
		$renderer = new Shortcode();
		?>
		<h3><?php esc_html_e( 'Saved mappings and cached previews', 'shootcal-social-feed' ); ?></h3>
		<p><?php esc_html_e( 'These previews use the same cached posts and filters as your site. Open each preview and confirm the selected photos before switching. No live Instagram request is made by these previews.', 'shootcal-social-feed' ); ?></p>
		<?php foreach ( $mappings as $old_id => $feed_id ) : ?>
			<details style="margin:1em 0;padding:1em;background:#fff;border:1px solid #c3c4c7;">
				<summary style="cursor:pointer;">
					<strong><?php echo esc_html( isset( $catalog[ $old_id ]['name'] ) ? (string) $catalog[ $old_id ]['name'] : sprintf( /* translators: %d: source feed ID. */ __( 'Smash Balloon feed %d', 'shootcal-social-feed' ), $old_id ) ); ?></strong>
					<?php echo esc_html( sprintf( /* translators: 1: legacy feed ID. 2: replacement feed ID. 3: replacement feed name. */ __( '(#%1$d) → ShootCal #%2$d: %3$s', 'shootcal-social-feed' ), $old_id, $feed_id, isset( $feeds[ $feed_id ]['name'] ) ? (string) $feeds[ $feed_id ]['name'] : __( 'Missing feed', 'shootcal-social-feed' ) ) ); ?>
				</summary>
				<p><code>[instagram-feed feed="<?php echo esc_html( (string) $old_id ); ?>"]</code> <?php esc_html_e( 'will render', 'shootcal-social-feed' ); ?> <code>[shootcal_instagram_feed feed="<?php echo esc_html( (string) $feed_id ); ?>"]</code></p>
				<?php
				// The shortcode renderer escapes all attributes and content; keep its SVG and style attributes.
				echo $renderer->render( array( 'feed' => $feed_id, 'dynamic' => 'false' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</details>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * @param array<string, mixed> $state Current import state.
	 */
	private static function render_switch( bool $enabled, array $state ): void {
		$can_activate = current_user_can( 'activate_plugins' );
		$active       = Smash_Import::active_plugins();
		$blockers     = $enabled ? array() : Smash_Import::switch_blockers();
		?>
		<?php if ( ! $enabled ) : ?>
			<h3><?php esc_html_e( 'Switch shortcodes to ShootCal', 'shootcal-social-feed' ); ?></h3>
			<p><?php esc_html_e( 'After switching, mapped [instagram-feed] shortcodes render through ShootCal without editing your pages. Smash Balloon feed data is preserved so you can undo the switch.', 'shootcal-social-feed' ); ?></p>
			<p><?php esc_html_e( 'Review any Instagram embeds hard-coded in your theme or external templates too. The automatic scan covers stored WordPress content, metadata, and widgets.', 'shootcal-social-feed' ); ?></p>
			<?php if ( ! empty( $blockers ) ) : ?>
				<div class="notice notice-warning inline"><p><strong><?php esc_html_e( 'Resolve these items before switching:', 'shootcal-social-feed' ); ?></strong></p><ul style="list-style:disc;padding-left:1.5em;">
					<?php foreach ( $blockers as $blocker ) : ?><li><?php echo esc_html( (string) $blocker ); ?></li><?php endforeach; ?>
				</ul></div>
			<?php endif; ?>
			<?php if ( $can_activate ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::SWITCH_ACTION ); ?>" />
					<input type="hidden" name="fingerprint" value="<?php echo esc_attr( Smash_Import::fingerprint( Smash_Import::catalog() ) ); ?>" />
					<?php wp_nonce_field( self::SWITCH_ACTION ); ?>
					<?php if ( ! empty( $active ) ) : ?>
						<p><label><input type="checkbox" name="deactivate" value="1" required <?php disabled( ! empty( $blockers ) ); ?> /> <?php esc_html_e( 'Deactivate Smash Balloon after checking these previews.', 'shootcal-social-feed' ); ?></label></p>
					<?php else : ?>
						<p><?php esc_html_e( 'Smash Balloon is inactive. This enables shortcode compatibility without changing any plugin activation.', 'shootcal-social-feed' ); ?></p>
						<p><label><input type="checkbox" name="preview_acknowledge" value="1" required <?php disabled( ! empty( $blockers ) ); ?> /> <?php esc_html_e( 'I checked the previews and want these feeds to replace the mapped Smash Balloon shortcodes.', 'shootcal-social-feed' ); ?></label></p>
					<?php endif; ?>
					<button type="submit" class="button button-primary" <?php disabled( ! empty( $blockers ) ); ?>><?php echo empty( $active ) ? esc_html__( 'Enable shortcode compatibility', 'shootcal-social-feed' ) : esc_html__( 'Switch shortcodes to ShootCal', 'shootcal-social-feed' ); ?></button>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'A WordPress administrator with permission to activate plugins must complete the switch.', 'shootcal-social-feed' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php if ( $enabled || ! empty( $state['deactivated'] ) ) : ?>
			<h3><?php esc_html_e( 'Undo switch', 'shootcal-social-feed' ); ?></h3>
			<p><?php esc_html_e( 'Turn off ShootCal compatibility for the old shortcodes. Only Smash Balloon plugins deactivated by this importer will be reactivated. Imported ShootCal feeds, saved mappings, and all original Smash Balloon data are kept.', 'shootcal-social-feed' ); ?></p>
			<?php if ( empty( $state['deactivated'] ) ) : ?>
				<p><?php esc_html_e( 'This importer did not deactivate a plugin. After undoing, activate Smash Balloon yourself if these pages still use its shortcodes.', 'shootcal-social-feed' ); ?></p>
			<?php endif; ?>
			<?php if ( $can_activate ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ROLLBACK_ACTION ); ?>" />
					<?php wp_nonce_field( self::ROLLBACK_ACTION ); ?>
					<?php submit_button( __( 'Undo switch', 'shootcal-social-feed' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php else : ?>
				<p><?php esc_html_e( 'A WordPress administrator with permission to activate plugins must undo the switch.', 'shootcal-social-feed' ); ?></p>
			<?php endif; ?>
		<?php endif; ?>
		<?php
	}

	private static function authorize( string $action, bool $activation = false ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Submit the importer form to make changes.', 'shootcal-social-feed' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( 'manage_options' ) || ( $activation && ! current_user_can( 'activate_plugins' ) ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this import action.', 'shootcal-social-feed' ), '', array( 'response' => 403 ) );
		}
		if ( ! wp_verify_nonce( self::post_text( '_wpnonce' ), $action ) ) {
			wp_die( esc_html__( 'This import form expired. Reload the Social Feed settings and try again.', 'shootcal-social-feed' ), '', array( 'response' => 403 ) );
		}
	}

	private static function post_text( string $key ): string {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/**
	 * @param true|\WP_Error $result Import operation result.
	 */
	private static function redirect_result( $result, string $success_message = '' ): void {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'error'   => is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : $success_message,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=shootcal-instagram-feed' ) . '#shootcal-smash-import' );
		exit;
	}

	private static function render_notice(): void {
		$key    = self::NOTICE_PREFIX . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! is_array( $notice ) || ! isset( $notice['message'] ) || ! is_string( $notice['message'] ) ) {
			return;
		}
		delete_transient( $key );
		printf(
			'<div class="notice %s inline"><p>%s</p></div>',
			! empty( $notice['error'] ) ? 'notice-error' : 'notice-success',
			esc_html( $notice['message'] )
		);
	}
}
