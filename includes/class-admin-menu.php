<?php
/**
 * Shared ShootCal admin navigation, available with either ShootCal plugin.
 *
 * Keep the dashboard in sync with ShootCal Web Calendar's Admin_Menu class.
 *
 * @package ShootCalInstagramFeed
 */

declare( strict_types=1 );

namespace ShootCalInstagramFeed;

defined( 'ABSPATH' ) || exit;

class Admin_Menu {

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ), 9 );
	}

	public function add_menu(): void {
		global $admin_page_hooks;
		// Each plugin carries this small dashboard. The first one owns the parent.
		if ( isset( $admin_page_hooks['shootcal'] ) ) {
			return;
		}
		add_menu_page( 'ShootCal Apps', 'ShootCal Apps', 'manage_options', 'shootcal', array( $this, 'render_page' ), PLUGIN_URL . 'assets/img/shootcal-logo.svg', 81 );
		add_submenu_page( 'shootcal', 'ShootCal Apps', self::item_label( __( 'Overview', 'shootcal-instagram-feed' ), 'dashicons-admin-home' ), 'manage_options', 'shootcal', array( $this, 'render_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( current_user_can( 'manage_options' ) ) {
			wp_enqueue_style( 'shootcal-apps-admin-menu', PLUGIN_URL . 'assets/css/admin-menu.css', array( 'dashicons' ), VERSION );
		}
	}

	public static function item_label( string $label, string $icon ): string {
		return '<span class="dashicons ' . esc_attr( $icon ) . ' shootcal-apps-menu-icon" aria-hidden="true"></span>' . esc_html( $label );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$installed = get_plugins();
		$plugins = array(
			'shootcal-web-calendar' => array(
				'name' => __( 'Calendar', 'shootcal-instagram-feed' ),
				'description' => __( 'Embed your ShootCal calendar or display another iCal feed.', 'shootcal-instagram-feed' ),
			),
			'shootcal-instagram-feed' => array(
				'name' => __( 'Social Feed', 'shootcal-instagram-feed' ),
				'description' => __( 'Display your Instagram photos with saved feeds and hashtag filters.', 'shootcal-instagram-feed' ),
			),
		);
		if ( isset( $installed['natural-photo-slider/natural-photo-slider.php'] ) ) {
			$plugins['natural-photo-slider'] = array(
				'name' => __( 'Photo Slider', 'shootcal-instagram-feed' ),
				'description' => __( 'Create lightweight photo sliders from your WordPress Media Library.', 'shootcal-instagram-feed' ),
			);
		}
		?>
		<div class="wrap shootcal-apps-overview">
			<h1 class="shootcal-apps-overview__heading"><img src="<?php echo esc_url( PLUGIN_URL . 'assets/img/shootcal-logo.svg' ); ?>" alt="" width="32" height="32" />ShootCal Apps</h1>
			<p><?php esc_html_e( 'Manage your ShootCal WordPress plugins.', 'shootcal-instagram-feed' ); ?></p>
			<div style="display:flex;flex-wrap:wrap;gap:16px;max-width:900px;">
			<?php foreach ( $plugins as $slug => $plugin ) :
				$file = $slug . '/' . $slug . '.php';
				$active = is_plugin_active( $file );
				$present = isset( $installed[ $file ] );
				$url = $active ? admin_url( 'admin.php?page=' . $slug ) : ( $present ? admin_url( 'plugins.php' ) : 'https://wordpress.org/plugins/' . $slug . '/' );
				?>
				<section class="card" style="flex:1 1 280px;margin:0;">
					<h2><?php echo esc_html( $plugin['name'] ); ?></h2>
					<p><?php echo esc_html( $plugin['description'] ); ?></p>
					<p><?php echo esc_html( $active ? __( 'Active', 'shootcal-instagram-feed' ) : ( $present ? __( 'Installed, inactive', 'shootcal-instagram-feed' ) : __( 'Not installed', 'shootcal-instagram-feed' ) ) ); ?><?php if ( $present ) : ?> · <?php echo esc_html( $installed[ $file ]['Version'] ); ?><?php endif; ?></p>
					<a class="button" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $active ? __( 'Settings', 'shootcal-instagram-feed' ) : ( $present ? __( 'Manage plugins', 'shootcal-instagram-feed' ) : __( 'View plugin', 'shootcal-instagram-feed' ) ) ); ?></a>
				</section>
			<?php endforeach; ?>
			</div>
		</div>
		<?php
	}
}
