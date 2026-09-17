<?php
/**
 * Explicit, reversible migration of saved Smash Balloon feed references.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Smash_Import {

	public const OPTION = 'shootcal_instagram_feed_smash_import';
	private const LOCK = 'shootcal_instagram_feed_smash_import_lock';
	private const PLUGINS = array( 'instagram-feed/instagram-feed.php', 'instagram-feed-pro/instagram-feed.php' );
	private static bool $read_failed = false;

	public function register(): void {
		add_action( 'init', array( $this, 'register_compatibility' ), 99 );
	}

	/** @return array<string,mixed> */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		foreach ( array( 'mappings', 'deactivated', 'fingerprints' ) as $key ) {
			$state[ $key ] = isset( $state[ $key ] ) && is_array( $state[ $key ] ) ? $state[ $key ] : array();
		}
		$state['enabled'] = ! empty( $state['enabled'] );
		return $state;
	}

	/** @return string[] */
	public static function active_plugins(): array {
		$active = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		return array_values( array_intersect( self::PLUGINS, array_merge( $active, $network ) ) );
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		self::$read_failed = self::$read_failed || '' !== $wpdb->last_error;
		return $table === $result;
	}

	/** Read display definitions only. Source credentials are never imported.
	 * @return array<int,array<string,mixed>>
	 */
	public static function catalog(): array {
		global $wpdb;
		self::$read_failed = false;
		$table = $wpdb->prefix . 'sbi_feeds';
		if ( ! self::table_exists( $table ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, feed_name, settings FROM %i ORDER BY id LIMIT 201', $table ), ARRAY_A );
		self::$read_failed = self::$read_failed || '' !== $wpdb->last_error || ! is_array( $rows );
		$sources = array();
		$source_table = $wpdb->prefix . 'sbi_sources';
		if ( self::table_exists( $source_table ) ) {
			$source_rows = $wpdb->get_results( $wpdb->prepare( 'SELECT account_id, username FROM %i', $source_table ), ARRAY_A );
			self::$read_failed = self::$read_failed || '' !== $wpdb->last_error || ! is_array( $source_rows );
			foreach ( (array) $source_rows as $source ) {
				$sources[ (string) $source['account_id'] ] = (string) $source['username'];
			}
		}
		$options = Config::get();
		$account_id = (string) $options['instagram_account_id'];
		$cache = Feed_Store::cache_for_account( $account_id );
		$username = strtolower( (string) ( $cache['account']['username'] ?? '' ) );
		$result = array();
		foreach ( (array) $rows as $row ) {
			$id = (int) $row['id'];
			if ( $id < 1 ) {
				continue;
			}
			$settings = json_decode( (string) $row['settings'], true );
			$messages = array();
			$valid = is_array( $settings );
			$s = $valid ? $settings : array();
			$ids = is_array( $s['id'] ?? null ) ? $s['id'] : explode( ',', self::scalar( $s['id'] ?? '' ) );
			$valid_ids = count( $ids ) === 1 && is_scalar( reset( $ids ) ) && '' !== self::scalar( reset( $ids ) );
			$ids = array_values( array_map( array( self::class, 'scalar' ), $ids ) );
			$source_id = count( $ids ) === 1 ? $ids[0] : '';
			$source_username = strtolower( $sources[ $source_id ] ?? '' );
			if ( '' === $source_username && is_array( $s['source_details'] ?? null ) && self::scalar( $s['source_details']['id'] ?? '' ) === $source_id ) {
				$source_username = strtolower( self::scalar( $s['source_details']['username'] ?? '' ) );
			}
			$same_account = '' !== $account_id && $valid_ids && ( $source_id === $account_id || ( '' !== $username && $username === $source_username ) );
			$can_import = $valid && $same_account && 'user' === ( $s['type'] ?? 'user' );
			if ( ! $valid ) {
				$messages[] = __( 'The saved definition is unreadable. Choose an existing replacement feed.', 'shootcal-social-feed' );
			}
			if ( ! $same_account || 'user' !== ( $s['type'] ?? 'user' ) ) {
				$messages[] = __( 'This is not a verified single-account feed for the connected ShootCal account. Automatic import is unavailable.', 'shootcal-social-feed' );
			}
			$include = self::hashtags( $s['includewords'] ?? '' );
			$exclude = self::hashtags( $s['excludewords'] ?? '' );
			if ( null === $include || null === $exclude ) {
				$can_import = false;
				$messages[] = __( 'Caption word or phrase filters need a manually chosen replacement. They cannot be converted to exact hashtags automatically.', 'shootcal-social-feed' );
			} elseif ( '' !== $include || '' !== $exclude ) {
				$messages[] = __( 'Hashtag filters will use exact hashtag matches, not Smash Balloon substring matches.', 'shootcal-social-feed' );
			}
			$moderation = $s['moderationlist'] ?? array();
			$moderation = is_string( $moderation ) ? json_decode( $moderation, true ) : $moderation;
			$restricted = ! is_array( $moderation ) || ( is_array( $moderation ) && array_diff( array_keys( $moderation ), array( 'list_type_selected', 'allow_list', 'block_list' ) ) ) || ! in_array( $s['sortby'] ?? 'none', array( 'none', 'recent' ), true ) || 0 !== self::integer( $s['offset'] ?? 0, 0, 0 ) || 'all' !== ( $s['media'] ?? 'all' ) || 'recent' !== ( $s['order'] ?? 'recent' );
			foreach ( array( 'allow_list', 'block_list' ) as $key ) {
				$restricted = $restricted || ( isset( $moderation[ $key ] ) && ! is_array( $moderation[ $key ] ) );
			}
			foreach ( array( 'hidephotos', 'whitelist', 'customBlockModerationlist' ) as $key ) {
				$restricted = $restricted || ! empty( $s[ $key ] );
			}
			foreach ( array( 'enablemoderationmode', 'moderationmode', 'shoppablefeed', 'permanent' ) as $key ) {
				$restricted = $restricted || ! self::boolean_valid( $s[ $key ] ?? false ) || self::truthy( $s[ $key ] ?? false );
			}
			foreach ( array( 'photosposts', 'videosposts', 'igtvposts', 'reelsposts' ) as $key ) {
				$restricted = $restricted || ( isset( $s[ $key ] ) && ( ! self::boolean_valid( $s[ $key ] ) || ! self::truthy( $s[ $key ] ) ) );
			}
			$restricted = $restricted || ! empty( $moderation['allow_list'] ) || ! empty( $moderation['block_list'] );
			if ( $restricted ) {
				$can_import = false;
				$messages[] = __( 'Sorting, media selection, moderation, or shopping rules need a manually chosen replacement feed.', 'shootcal-social-feed' );
			}
			$num = self::integer( $s['num'] ?? 20, 1, 30 );
			$cols = self::integer( $s['cols'] ?? 4, 1, 6 );
			$mobile = self::integer( $s['nummobile'] ?? 20, 0, 30 );
			if ( null === $num || null === $cols || null === $mobile || 'grid' !== ( $s['layout'] ?? 'grid' ) ) {
				$can_import = false;
				$messages[] = __( 'Automatic import supports grid feeds with 1–30 posts and 1–6 desktop columns.', 'shootcal-social-feed' );
			}
			$name = wp_check_invalid_utf8( substr( sanitize_text_field( (string) $row['feed_name'] ), 0, 80 ), true );
			$result[ $id ] = array(
				/* translators: %d: saved source feed ID. */
				'name' => '' !== $name ? $name : sprintf( __( 'Feed %d', 'shootcal-social-feed' ), $id ),
				'can_import' => $can_import,
				'messages' => $messages,
				'fingerprint' => hash( 'sha256', (string) $row['settings'] . $name ),
				'candidate' => $can_import ? array( 'name' => '' !== $name ? $name : 'Instagram ' . $id, 'hashtag' => $include, 'exclude' => $exclude, 'limit' => $num, 'columns' => $cols, 'mobile_limit' => $mobile, 'follow' => self::truthy( $s['showfollow'] ?? true ), 'dynamic' => true ) : null,
			);
		}
		return $result;
	}

	public static function fingerprint( array $catalog ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $catalog, Feeds::all(), self::state(), Config::get()['instagram_account_id'] ) ) );
	}

	/** @return true|\WP_Error */
	public static function import( array $choices, string $fingerprint, bool $ack ) {
		if ( ! current_user_can( 'manage_options' ) || ! $ack ) {
			return self::error( __( 'Review and acknowledge the import differences first.', 'shootcal-social-feed' ) );
		}
		return self::locked( static function () use ( $choices, $fingerprint ) {
			$catalog = self::catalog();
			if ( self::$read_failed ) {
				return self::error( __( 'WordPress could not read all saved feed definitions. Import was stopped.', 'shootcal-social-feed' ) );
			}
			if ( ! hash_equals( self::fingerprint( $catalog ), $fingerprint ) ) {
				return self::error( __( 'Feed settings changed. Reload the preview before importing.', 'shootcal-social-feed' ) );
			}
			$state = self::state();
			if ( $state['enabled'] ) {
				return self::error( __( 'Undo the shortcode switch before changing import mappings.', 'shootcal-social-feed' ) );
			}
			if ( isset( $state['account_id'] ) && $state['account_id'] !== (string) Config::get()['instagram_account_id'] ) {
				// A new connection must not silently authorize old account mappings.
				$state['mappings'] = array();
				$state['fingerprints'] = array();
			}
			$plan = array();
			foreach ( $choices as $old_id => $choice ) {
				if ( 'skip' === $choice || '' === $choice ) {
					continue;
				}
				if ( ! ctype_digit( (string) $old_id ) || ! isset( $catalog[ (int) $old_id ] ) || ! is_scalar( $choice ) ) {
					return self::error( __( 'An import selection is invalid.', 'shootcal-social-feed' ) );
				}
				if ( 'new' === $choice ) {
					if ( ! $catalog[ $old_id ]['can_import'] ) {
						return self::error( __( 'This feed needs a manually chosen replacement.', 'shootcal-social-feed' ) );
					}
					// Re-import is idempotent and preserves edits to an already imported preset.
					$existing = (int) ( $state['mappings'][ $old_id ] ?? 0 );
					$plan[ $old_id ] = Feeds::get( $existing ) ? $existing : $catalog[ $old_id ]['candidate'];
				} elseif ( ctype_digit( (string) $choice ) && Feeds::get( (int) $choice ) ) {
					$plan[ $old_id ] = (int) $choice;
				} else {
					return self::error( __( 'A selected replacement feed no longer exists.', 'shootcal-social-feed' ) );
				}
			}
			if ( ! $plan ) {
				return self::error( __( 'Select at least one feed to import or map.', 'shootcal-social-feed' ) );
			}
			return self::transaction( static function () use ( $plan, $state, $catalog, $fingerprint ) {
				if ( ! hash_equals( self::fingerprint( self::catalog() ), $fingerprint ) || self::$read_failed ) {
					return self::error( __( 'Feed settings changed. Reload before importing.', 'shootcal-social-feed' ) );
				}
				foreach ( $plan as $old_id => $feed ) {
					$new_id = is_array( $feed ) ? Feeds::save( 0, $feed ) : $feed;
					if ( is_wp_error( $new_id ) ) {
						return $new_id;
					}
					$state['mappings'][ $old_id ] = $new_id;
					$state['fingerprints'][ $old_id ] = $catalog[ $old_id ]['fingerprint'];
				}
				$state['account_id'] = (string) Config::get()['instagram_account_id'];
				return self::save_state( $state );
			} );
		} );
	}

	/** @return string[] */
	public static function switch_blockers(): array {
		$errors = array();
		$state = self::state();
		$options = Config::get();
		$cache = Feed_Store::cache_for_account( (string) $options['instagram_account_id'] );
		$status = Feed_Store::status();
		if ( ! Config::has_token() || empty( $cache['items'] ) || (int) ( $status['last_success'] ?? 0 ) < time() - DAY_IN_SECONDS ) {
			$errors[] = __( 'Connect Instagram and successfully refresh the ShootCal feed within the last day before switching.', 'shootcal-social-feed' );
		}
		if ( empty( $state['mappings'] ) || ( $state['account_id'] ?? '' ) !== (string) $options['instagram_account_id'] ) {
			$errors[] = __( 'Import feed mappings for the current Instagram account first.', 'shootcal-social-feed' );
		}
		$catalog = self::catalog();
		if ( self::$read_failed ) {
			$errors[] = __( 'WordPress could not read every saved feed. The switch is blocked.', 'shootcal-social-feed' );
		}
		if ( count( $catalog ) > 200 ) {
			$errors[] = __( 'This site has more than 200 saved feeds. Automatic switching is unavailable.', 'shootcal-social-feed' );
		}
		foreach ( array_unique( array_merge( array_keys( $catalog ), array_keys( $state['mappings'] ) ) ) as $old_id ) {
			if ( isset( $catalog[ $old_id ] ) && ( $state['fingerprints'][ $old_id ] ?? '' ) !== $catalog[ $old_id ]['fingerprint'] ) {
				/* translators: %d: saved source feed ID. */
				$errors[] = sprintf( __( 'Review and import the current definition for feed %d before switching.', 'shootcal-social-feed' ), $old_id );
			}
			$preset = Feeds::get( (int) ( $state['mappings'][ $old_id ] ?? 0 ) );
			if ( ! $preset ) {
				/* translators: %d: saved source feed ID. */
				$errors[] = sprintf( __( 'Saved Smash Balloon feed %d still needs a replacement.', 'shootcal-social-feed' ), $old_id );
				continue;
			}
			$items = Hashtag_Filter::filter_list( (array) ( $cache['items'] ?? array() ), Hashtag_Filter::normalize_list( $preset['hashtag'] ) ?? array(), Hashtag_Filter::normalize_list( $preset['exclude'] ) ?? array() );
			if ( ! $items ) {
				/* translators: %d: saved source feed ID. */
				$errors[] = sprintf( __( 'The replacement for feed %d has no cached posts to preview.', 'shootcal-social-feed' ), $old_id );
			}
		}
		if ( is_multisite() ) {
			$network = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
			if ( array_intersect( self::PLUGINS, $network ) ) {
				$errors[] = __( 'Smash Balloon is network activated. A site-level switch cannot deactivate it.', 'shootcal-social-feed' );
			}
		}
		global $shortcode_tags;
		if ( isset( $shortcode_tags['instagram-feed'] ) && ! self::known_handler( $shortcode_tags['instagram-feed'] ) ) {
			$errors[] = __( 'Another plugin owns the instagram-feed shortcode. Resolve that conflict before switching.', 'shootcal-social-feed' );
		}
		foreach ( array( 'smashballoon/instagram-feed', 'sbi/sbi-feed-block' ) as $name ) {
			$block = \WP_Block_Type_Registry::get_instance()->get_registered( $name );
			if ( $block && ! self::known_handler( $block->render_callback ) ) {
				$errors[] = __( 'Another plugin owns an Instagram block. Resolve that conflict before switching.', 'shootcal-social-feed' );
			}
		}
		return array_values( array_unique( array_merge( $errors, self::usage_blockers() ) ) );
	}

	private static function known_handler( $callback ): bool {
		if ( is_array( $callback ) && $callback[0] instanceof self ) {
			return true;
		}
		try {
			$reflection = is_array( $callback ) ? new \ReflectionMethod( $callback[0], $callback[1] ) : new \ReflectionFunction( $callback );
			$file = wp_normalize_path( (string) $reflection->getFileName() );
			foreach ( self::active_plugins() as $plugin ) {
				if ( str_starts_with( $file, wp_normalize_path( WP_PLUGIN_DIR . '/' . dirname( $plugin ) ) . '/' ) ) {
					return true;
				}
			}
		} catch ( \Throwable $error ) {
			return false;
		}
		return false;
	}

	/** @return true|\WP_Error */
	public static function switch( bool $deactivate, string $fingerprint = '' ) {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'activate_plugins' ) ) {
			return self::error( __( 'You are not allowed to switch plugins.', 'shootcal-social-feed' ) );
		}
		return self::locked( static function () use ( $deactivate, $fingerprint ) {
			if ( '' !== $fingerprint && ! hash_equals( self::fingerprint( self::catalog() ), $fingerprint ) ) {
				return self::error( __( 'The preview changed. Reload and review it before switching.', 'shootcal-social-feed' ) );
			}
			$blockers = self::switch_blockers();
			if ( $blockers ) {
				return self::error( implode( ' ', $blockers ) );
			}
			$active = self::active_plugins();
			if ( $active && ! $deactivate ) {
				return self::error( __( 'Select the option to deactivate Smash Balloon before switching.', 'shootcal-social-feed' ) );
			}
			$state = self::state();
			$state['enabled'] = true;
			$state['deactivated'] = array_values( array_unique( array_merge( $state['deactivated'], $active ) ) );
			// Persist recovery information before any plugin is deactivated.
			$saved = self::save_state( $state );
			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( $active as $plugin ) {
				deactivate_plugins( $plugin, false, false );
			}
			if ( self::active_plugins() ) {
				return self::error( __( 'Smash Balloon is still active. Shortcodes remain with it; use Undo switch before retrying.', 'shootcal-social-feed' ) );
			}
			return true;
		} );
	}

	/** @return true|\WP_Error */
	public static function rollback() {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'activate_plugins' ) ) {
			return self::error( __( 'You are not allowed to switch plugins.', 'shootcal-social-feed' ) );
		}
		return self::locked( static function () {
			$state = self::state();
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
			foreach ( array_intersect( self::PLUGINS, (array) $state['deactivated'] ) as $plugin ) {
				if ( ! is_plugin_active( $plugin ) ) {
					$result = activate_plugin( $plugin, '', false, false );
					if ( is_wp_error( $result ) || ! is_plugin_active( $plugin ) ) {
						return self::error( __( 'WordPress could not reactivate Smash Balloon. Compatibility remains enabled; restore the plugin on the Plugins page before retrying.', 'shootcal-social-feed' ) );
					}
				}
			}
			$state['enabled'] = false;
			$state['deactivated'] = array();
			return self::save_state( $state );
		} );
	}

	public function register_compatibility(): void {
		if ( empty( self::state()['enabled'] ) || self::active_plugins() ) {
			return;
		}
		if ( ! shortcode_exists( 'instagram-feed' ) ) {
			add_shortcode( 'instagram-feed', array( $this, 'render' ) );
		}
		foreach ( array( 'smashballoon/instagram-feed', 'sbi/sbi-feed-block' ) as $name ) {
			if ( ! \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				register_block_type( $name, array( 'api_version' => 2, 'attributes' => array( 'feedId' => array( 'type' => 'string' ), 'shortcodeSettings' => array( 'type' => 'string' ) ), 'render_callback' => array( $this, 'render_block' ) ) );
			}
		}
	}

	public function render( $attributes = array() ): string {
		$atts = self::translate_attributes( is_array( $attributes ) ? $attributes : array() );
		if ( is_wp_error( $atts ) ) {
			return current_user_can( 'manage_options' ) ? '<p class="shootcal-instagram-feed__empty">' . esc_html( $atts->get_error_message() ) . '</p>' : '';
		}
		return ( new Shortcode() )->render( $atts );
	}

	public function render_block( array $attributes ): string {
		return $this->render( self::block_attributes( $attributes ) );
	}

	/** @return array<string,mixed> */
	private static function block_attributes( array $attributes ): array {
		if ( isset( $attributes['feedId'] ) ) {
			return array( 'feed' => $attributes['feedId'] );
		}
		$text = self::scalar( $attributes['shortcodeSettings'] ?? '' );
		$text = preg_replace( '/^\[instagram-feed\s*|\]$/', '', trim( $text ) );
		$atts = shortcode_parse_atts( $text );
		return is_array( $atts ) ? $atts : array();
	}

	/** @return array<string,mixed>|\WP_Error */
	public static function translate_attributes( array $attributes ) {
		$state = self::state();
		$id = self::integer( $attributes['feed'] ?? '', 1, PHP_INT_MAX );
		if ( null === $id || empty( $state['mappings'][ $id ] ) || ! Feeds::get( (int) $state['mappings'][ $id ] ) ) {
			return self::error( __( 'This legacy Instagram shortcode needs a mapped feed ID in ShootCal settings.', 'shootcal-social-feed' ) );
		}
		if ( ( $state['account_id'] ?? '' ) !== (string) Config::get()['instagram_account_id'] ) {
			return self::error( __( 'The imported feed belongs to a different Instagram connection. Review its mapping.', 'shootcal-social-feed' ) );
		}
		$result = array( 'feed' => (int) $state['mappings'][ $id ] );
		foreach ( $attributes as $key => $value ) {
			if ( 'feed' === $key ) {
				continue;
			}
			if ( ! is_scalar( $value ) ) {
				return self::error( __( 'The legacy shortcode has an invalid setting.', 'shootcal-social-feed' ) );
			}
			$numeric = array( 'num' => array( 'limit', 1, 30 ), 'cols' => array( 'columns', 1, 6 ), 'nummobile' => array( 'mobile_limit', 0, 30 ) );
			if ( isset( $numeric[ $key ] ) ) {
				$rule = $numeric[ $key ];
				$number = self::integer( $value, $rule[1], $rule[2] );
				if ( null === $number ) {
					return self::error( __( 'The legacy shortcode has an unsupported post or column count.', 'shootcal-social-feed' ) );
				}
				$result[ $rule[0] ] = $number;
			} elseif ( 'showfollow' === $key && in_array( strtolower( (string) $value ), array( 'true', 'false', '1', '0' ), true ) ) {
				$result['follow'] = self::truthy( $value ) ? 'true' : 'false';
			} elseif ( 'class' === $key ) {
				$result['class'] = (string) $value;
			} else {
				/* translators: %s: unsupported shortcode attribute name. */
				return self::error( sprintf( __( 'Legacy shortcode option "%s" needs a manual update before switching.', 'shootcal-social-feed' ), sanitize_key( (string) $key ) ) );
			}
		}
		return $result;
	}

	/** Scan stored live content without rewriting it. Templates outside the database need manual review.
	 * @return string[]
	 */
	private static function usage_blockers(): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( 'instagram-feed' ) . '%';
		$legacy = '%' . $wpdb->esc_like( 'sbi-' ) . '%';
		$elementor = '%' . $wpdb->esc_like( 'sb-instagram' ) . '%';
		$queries = array(
			$wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE post_type != 'revision' AND post_status NOT IN ('trash','auto-draft') AND (post_content LIKE %s OR post_content LIKE %s OR post_content LIKE %s)", $like, $legacy, $elementor ),
			$wpdb->prepare( "SELECT m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type != 'revision' AND p.post_status NOT IN ('trash','auto-draft') AND (m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s)", $like, $legacy, $elementor ),
			$wpdb->prepare( "SELECT description FROM {$wpdb->term_taxonomy} WHERE description LIKE %s OR description LIKE %s", $like, $legacy ),
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND (option_value LIKE %s OR option_value LIKE %s)", $wpdb->esc_like( 'widget_' ) . '%', $like, $legacy ),
		);
		$errors = array();
		foreach ( $queries as $query ) {
			// Queries above are prepared; keep separate checks so an error cannot look like an empty scan.
			$rows = $wpdb->get_col( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) {
				$errors[] = __( 'WordPress could not complete the shortcode usage scan. The switch is blocked.', 'shootcal-social-feed' );
				continue;
			}
			foreach ( $rows as $text ) {
				self::check_content( (string) $text, $errors );
			}
		}
		foreach ( (array) get_option( 'sidebars_widgets', array() ) as $sidebar => $widgets ) {
			if ( 'wp_inactive_widgets' === $sidebar || ! is_array( $widgets ) ) {
				continue;
			}
			foreach ( $widgets as $widget ) {
				if ( is_string( $widget ) && str_starts_with( $widget, 'instagram-feed-widget-' ) ) {
					$errors[] = __( 'Replace the active Smash Balloon widget with a Shortcode block before switching.', 'shootcal-social-feed' );
				}
			}
		}
		return array_values( array_unique( $errors ) );
	}

	private static function check_content( string $text, array &$errors ): void {
		if ( str_contains( $text, 'sbi-widget' ) || str_contains( $text, 'sb-instagram-feed' ) ) {
			$errors[] = __( 'An Elementor Instagram widget needs to be replaced with a Shortcode widget before switching.', 'shootcal-social-feed' );
		}
		// Builder JSON can escape shortcode quotes. Parse strings as data, never instantiate objects.
		$data = json_decode( $text, true );
		if ( is_array( $data ) ) {
			array_walk_recursive( $data, static function ( $value ) use ( &$errors ) {
				if ( is_string( $value ) && str_contains( $value, '[instagram-feed' ) ) {
					self::check_content( $value, $errors );
				}
			} );
			return;
		}
		if ( preg_match_all( '/' . get_shortcode_regex( array( 'instagram-feed' ) ) . '/s', $text, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				if ( '[' === $match[1] && ']' === $match[6] ) {
					continue;
				}
				$atts = shortcode_parse_atts( $match[3] );
				$result = self::translate_attributes( is_array( $atts ) ? $atts : array() );
				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}
		}
		foreach ( parse_blocks( $text ) as $block ) {
			self::check_block( $block, $errors );
		}
	}

	private static function check_block( array $block, array &$errors ): void {
		if ( in_array( $block['blockName'] ?? '', array( 'smashballoon/instagram-feed', 'sbi/sbi-feed-block' ), true ) ) {
			$result = self::translate_attributes( self::block_attributes( $block['attrs'] ?? array() ) );
			if ( is_wp_error( $result ) ) {
				$errors[] = $result->get_error_message();
			}
		}
		foreach ( $block['innerBlocks'] ?? array() as $inner ) {
			self::check_block( $inner, $errors );
		}
	}

	private static function scalar( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function integer( $value, int $min, int $max ): ?int {
		$value = self::scalar( $value );
		if ( ! ctype_digit( $value ) || strlen( $value ) > strlen( (string) PHP_INT_MAX ) || (float) $value > PHP_INT_MAX ) {
			return null;
		}
		return (int) $value >= $min && (int) $value <= $max ? (int) $value : null;
	}

	private static function truthy( $value ): bool {
		return in_array( strtolower( self::scalar( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	private static function boolean_valid( $value ): bool {
		return is_scalar( $value ) && in_array( strtolower( self::scalar( $value ) ), array( '', '0', '1', 'false', 'true', 'yes', 'no', 'on', 'off' ), true );
	}

	private static function hashtags( $value ): ?string {
		if ( ! is_scalar( $value ) ) {
			return null;
		}
		$tags = array();
		foreach ( explode( ',', trim( (string) $value ) ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( ! str_starts_with( $part, '#' ) || '' === Hashtag_Filter::normalize( $part ) ) {
				return null;
			}
			$tags[] = Hashtag_Filter::normalize( $part );
		}
		return implode( ', ', array_unique( $tags ) );
	}

	private static function error( string $message ): \WP_Error {
		return new \WP_Error( 'shootcal_smash_import', $message );
	}

	/** @return true|\WP_Error */
	private static function save_state( array $state ) {
		update_option( self::OPTION, $state, false );
		return get_option( self::OPTION ) === $state ? true : self::error( __( 'WordPress could not save the import state. No switch was performed.', 'shootcal-social-feed' ) );
	}

	private static function locked( callable $operation ) {
		global $wpdb;
		$old = get_option( self::LOCK, false );
		if ( false !== $old && is_numeric( $old ) && (int) $old < time() - 120 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", self::LOCK, (string) $old ) );
			wp_cache_delete( self::LOCK, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
		if ( ! add_option( self::LOCK, time(), '', false ) ) {
			return self::error( __( 'Another migration is running. If it was interrupted, reload after two minutes.', 'shootcal-social-feed' ) );
		}
		try {
			return $operation();
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** Keep newly created presets and their mapping in one database transaction. */
	private static function transaction( callable $operation ) {
		global $wpdb;
		$engine = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $wpdb->options ) ), ARRAY_A );
		if ( 'InnoDB' !== ( $engine['Engine'] ?? '' ) ) {
			return self::error( __( 'Import requires a transactional WordPress options table.', 'shootcal-social-feed' ) );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::error( __( 'WordPress could not start an atomic import.', 'shootcal-social-feed' ) );
		}
		try {
			$wpdb->get_results( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN (%s,%s) FOR UPDATE", FEEDS_KEY, self::OPTION ) );
			if ( '' !== $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				return self::error( __( 'WordPress could not lock the import settings. No feeds were changed.', 'shootcal-social-feed' ) );
			}
			self::clear_option_cache();
			$result = $operation();
			if ( is_wp_error( $result ) ) {
				$wpdb->query( 'ROLLBACK' );
			} elseif ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				$result = self::error( __( 'The import could not be committed.', 'shootcal-social-feed' ) );
			}
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			return self::error( __( 'The import failed. Existing feeds were preserved.', 'shootcal-social-feed' ) );
		} finally {
			self::clear_option_cache();
		}
	}

	private static function clear_option_cache(): void {
		foreach ( array( FEEDS_KEY, self::OPTION, 'alloptions', 'notoptions' ) as $key ) {
			wp_cache_delete( $key, 'options' );
		}
	}
}
