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
	private const PAGE            = 'shootcal-social-feed-import';
	private const NOTICE_PREFIX   = 'shootcal_instagram_smash_notice_';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_post_' . self::IMPORT_ACTION, array( $this, 'handle_import' ) );
		add_action( 'admin_post_' . self::SWITCH_ACTION, array( $this, 'handle_switch' ) );
		add_action( 'admin_post_' . self::ROLLBACK_ACTION, array( $this, 'handle_rollback' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_preview_assets' ) );
	}

	public function enqueue_preview_assets( string $hook ): void {
		// Another ShootCal plugin can own the parent menu and its hook prefix.
		if ( ( str_ends_with( $hook, '_page_shootcal-instagram-feed' ) || str_ends_with( $hook, '_page_' . self::PAGE ) ) && current_user_can( 'manage_options' ) ) {
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
		self::redirect_result( $result, __( 'Selected feeds were imported. Review the cached previews before switching the old shortcodes to ShootCal.', 'shootcal-social-feed' ), is_wp_error( $result ) ? 'feeds' : 'preview' );
	}

	public function handle_switch(): void {
		self::authorize( self::SWITCH_ACTION, true );
		$active = Smash_Import::active_plugins();
		if ( ! empty( $active ) && '1' !== self::post_text( 'deactivate' ) ) {
			self::redirect_result( new \WP_Error( 'deactivate_not_confirmed', __( 'Review the previews and check the deactivation confirmation before switching.', 'shootcal-social-feed' ) ), '', 'switch' );
		}
		if ( empty( $active ) && '1' !== self::post_text( 'preview_acknowledge' ) ) {
			self::redirect_result( new \WP_Error( 'preview_not_confirmed', __( 'Review the previews and confirm they show the feeds you want before enabling shortcode compatibility.', 'shootcal-social-feed' ) ), '', 'switch' );
		}
		$fingerprint = self::post_text( 'fingerprint' );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint ) ) {
			self::redirect_result( new \WP_Error( 'invalid_preview', __( 'Reload and review the feed previews before switching.', 'shootcal-social-feed' ) ), '', 'switch' );
		}
		$result = Smash_Import::switch( '1' === self::post_text( 'deactivate' ), $fingerprint );
		self::redirect_result( $result, __( 'Shortcode compatibility is enabled. Mapped Smash Balloon shortcodes now use ShootCal. Clear your page cache and check the affected pages.', 'shootcal-social-feed' ), is_wp_error( $result ) ? 'switch' : 'done' );
	}

	public function handle_rollback(): void {
		self::authorize( self::ROLLBACK_ACTION, true );
		$result = Smash_Import::rollback();
		self::redirect_result( $result, __( 'Shortcode compatibility was turned off. Smash Balloon plugins deactivated by this importer were reactivated. Imported ShootCal feeds and the original Smash Balloon data were preserved.', 'shootcal-social-feed' ), is_wp_error( $result ) ? 'done' : 'preview' );
	}

	public function register_page(): void {
		add_submenu_page( 'options.php', __( 'Import from Smash Balloon', 'shootcal-social-feed' ), __( 'Import from Smash Balloon', 'shootcal-social-feed' ), 'manage_options', self::PAGE, array( self::class, 'render_page' ) );
	}

	private static function url( string $step = 'start' ): string {
		return add_query_arg( array( 'page' => self::PAGE, 'step' => $step ), admin_url( 'admin.php' ) );
	}

	/** The settings page is only an entry point; never scan legacy tables here. */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = Smash_Import::state();
		$step = $state['enabled'] ? 'done' : ( $state['mappings'] ? 'preview' : 'start' );
		?>
		<section id="shootcal-smash-import" style="max-width:75em;margin-top:2.5em;">
			<h2><?php esc_html_e( 'Moving from Smash Balloon?', 'shootcal-social-feed' ); ?></h2>
			<p><?php esc_html_e( 'A guided import helps you keep supported Instagram shortcodes already on your pages.', 'shootcal-social-feed' ); ?></p>
			<a class="button button-secondary" href="<?php echo esc_url( self::url( $step ) ); ?>"><?php echo $state['enabled'] ? esc_html__( 'Manage imported shortcodes', 'shootcal-social-feed' ) : esc_html__( 'Import from Smash Balloon', 'shootcal-social-feed' ); ?></a>
		</section>
		<?php
	}

	/** A separate, server-rendered walkthrough; visiting a step never imports or switches. */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$state = Smash_Import::state();
		$step = isset( $_GET['step'] ) && is_string( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'start';
		$steps = array( 'start' => __( 'Before you begin', 'shootcal-social-feed' ), 'feeds' => __( 'Choose feeds', 'shootcal-social-feed' ), 'preview' => __( 'Preview', 'shootcal-social-feed' ), 'switch' => __( 'Switch', 'shootcal-social-feed' ) );
		if ( ! isset( $steps[ $step ] ) && 'done' !== $step ) {
			$step = 'start';
		}
		if ( $state['enabled'] ) {
			$step = 'done';
		} elseif ( 'done' === $step ) {
			$step = $state['mappings'] ? 'preview' : 'start';
		} elseif ( in_array( $step, array( 'preview', 'switch' ), true ) && ! $state['mappings'] ) {
			$step = 'feeds';
		}
		?>
		<div class="wrap" id="shootcal-smash-walkthrough" style="max-width:75em;">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=shootcal-instagram-feed' ) ); ?>"><?php esc_html_e( 'Back to Social Feed', 'shootcal-social-feed' ); ?></a></p>
			<h1><?php esc_html_e( 'Import from Smash Balloon', 'shootcal-social-feed' ); ?></h1>
			<ol aria-label="<?php esc_attr_e( 'Import progress', 'shootcal-social-feed' ); ?>" style="display:flex;flex-wrap:wrap;gap:1em 2em;margin:1.5em 0 1.5em 1.5em;">
				<?php foreach ( $steps as $key => $label ) : ?>
					<li <?php if ( $key === $step || ( 'done' === $step && 'switch' === $key ) ) : ?>aria-current="step" style="font-weight:600;"<?php endif; ?>><?php echo esc_html( $label ); ?></li>
				<?php endforeach; ?>
			</ol>
			<?php self::render_notice(); ?>
			<?php if ( 'start' === $step ) : ?>
				<?php self::render_start(); ?>
			<?php elseif ( 'feeds' === $step ) : ?>
				<?php self::render_choices(); ?>
			<?php elseif ( 'preview' === $step ) : ?>
				<?php self::render_previews( Smash_Import::catalog(), $state['mappings'], Feeds::all() ); ?>
				<p><a class="button" href="<?php echo esc_url( self::url( 'feeds' ) ); ?>"><?php esc_html_e( 'Back to choose feeds', 'shootcal-social-feed' ); ?></a> <a class="button button-primary" href="<?php echo esc_url( self::url( 'switch' ) ); ?>"><?php esc_html_e( 'Continue to switch', 'shootcal-social-feed' ); ?></a></p>
			<?php elseif ( 'switch' === $step ) : ?>
				<?php self::render_switch( false, $state ); ?>
				<p><a class="button" href="<?php echo esc_url( self::url( 'preview' ) ); ?>"><?php esc_html_e( 'Back to previews', 'shootcal-social-feed' ); ?></a></p>
			<?php else : ?>
				<h2><?php esc_html_e( 'Your shortcode mappings are saved', 'shootcal-social-feed' ); ?></h2>
				<?php if ( Smash_Import::active_plugins() ) : ?>
					<div class="notice notice-warning inline"><p><?php esc_html_e( 'Smash Balloon is active, so ShootCal is leaving its shortcodes with that plugin. Undo the switch below, then review and switch again when ready.', 'shootcal-social-feed' ); ?></p></div>
				<?php else : ?>
					<p><?php esc_html_e( 'ShootCal now renders your mapped Instagram shortcodes. The shortcodes in your page content were not changed. Clear your page cache and check the affected pages.', 'shootcal-social-feed' ); ?></p>
				<?php endif; ?>
				<p><?php esc_html_e( 'Keep Smash Balloon installed and inactive until you have checked your pages. If you later delete it, enable its Preserve settings if plugin is removed option first if you want to retain its data. Undo may require reinstalling Smash Balloon after deletion.', 'shootcal-social-feed' ); ?></p>
				<?php self::render_switch( true, $state ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_start(): void {
		$connected = Config::has_token() && '' !== (string) Config::get()['instagram_account_id'];
		?>
		<h2><?php esc_html_e( 'Keep the shortcodes already on your pages', 'shootcal-social-feed' ); ?></h2>
		<p><?php esc_html_e( 'For supported feeds, ShootCal can take over shortcodes such as [instagram-feed feed="12"] without editing your page HTML. You will choose the feeds, review their appearance, then make an explicit switch.', 'shootcal-social-feed' ); ?></p>
		<ul style="list-style:disc;padding-left:1.5em;max-width:55em;">
			<li><?php esc_html_e( 'Connect the same Instagram account to ShootCal first. Import uses ShootCal’s connection and cached posts; it does not copy Smash Balloon credentials.', 'shootcal-social-feed' ); ?></li>
			<li><?php esc_html_e( 'Leave Smash Balloon active while you import and preview if it is serving your live pages. The final switch can deactivate it and hand supported shortcodes to ShootCal together.', 'shootcal-social-feed' ); ?></li>
			<li><?php esc_html_e( 'Already deactivated or removed Smash Balloon? Import still works when its saved feed data remains in this WordPress database. Do not delete it just to start this walkthrough: deletion can erase that data unless its Preserve settings if plugin is removed option was enabled.', 'shootcal-social-feed' ); ?></li>
		</ul>
		<?php if ( $connected ) : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( self::url( 'feeds' ) ); ?>"><?php esc_html_e( 'Choose feeds to import', 'shootcal-social-feed' ); ?></a></p>
		<?php else : ?>
			<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=shootcal-instagram-feed' ) ); ?>"><?php esc_html_e( 'Connect Instagram in Social Feed', 'shootcal-social-feed' ); ?></a></p>
		<?php endif; ?>
		<?php
	}

	private static function render_choices(): void {
		$catalog = Smash_Import::catalog();
		$state = Smash_Import::state();
		$feeds = Feeds::all();
		$mappings = $state['mappings'];
		?>
		<h2><?php esc_html_e( 'Choose the feeds you want to bring over', 'shootcal-social-feed' ); ?></h2>
		<p><?php esc_html_e( 'Only selected feeds are imported. Your live shortcodes stay with their current plugin until the final switch.', 'shootcal-social-feed' ); ?></p>
		<details style="margin:1em 0;"><summary><?php esc_html_e( 'What will look different?', 'shootcal-social-feed' ); ?></summary><p><?php esc_html_e( 'ShootCal uses its own cached posts and grid styling, up to two phone columns, and links to Instagram. Headers, captions, likes, lightboxes, Load More, custom CSS, and custom button styles are not copied. Review each feed’s notes for unsupported settings.', 'shootcal-social-feed' ); ?></p></details>
		<?php if ( empty( $catalog ) ) : ?>
			<p><?php esc_html_e( 'No saved Smash Balloon Instagram feeds were found. If removal deleted its settings, restore those settings from a backup before importing, or create your feeds directly in ShootCal.', 'shootcal-social-feed' ); ?></p>
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
		<p><a class="button" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Back', 'shootcal-social-feed' ); ?></a></p>
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
						<p><?php esc_html_e( 'Smash Balloon is not active. This enables shortcode compatibility without changing any plugin activation.', 'shootcal-social-feed' ); ?></p>
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
	private static function redirect_result( $result, string $success_message = '', string $step = 'feeds' ): void {
		set_transient(
			self::NOTICE_PREFIX . get_current_user_id(),
			array(
				'error'   => is_wp_error( $result ),
				'message' => is_wp_error( $result ) ? $result->get_error_message() : $success_message,
			),
			5 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( self::url( $step ) );
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
