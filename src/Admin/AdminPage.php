<?php
/**
 * The Bitlane screen in wp-admin.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Admin;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Connection\ConnectionStore;
use Bitlane\WordPress\Connection\OAuthClient;
use Bitlane\WordPress\Http\ConnectionController;

/**
 * A top-level "Bitlane" menu with the screen's React app (assets/admin,
 * built into build/), Bitlane's OAuth callback on the same page, and a notice
 * on every admin page once Bitlane ended the connection.
 */
final class AdminPage implements HasHooks {

	public const SLUG = 'bitlane';

	/**
	 * Builds the page.
	 *
	 * @param ConnectionStore      $connection The stored connection.
	 * @param OAuthClient          $oauth      The OAuth client.
	 * @param ConnectionController $controller Connects the site after the callback.
	 * @param string               $version    The plugin's version, for the assets.
	 */
	public function __construct(
		private ConnectionStore $connection,
		private OAuthClient $oauth,
		private ConnectionController $controller,
		private string $version,
	) {}

	/**
	 * Adds the menu, the callback, the script and the notice.
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'callback' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BITLANE_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * The top-level menu, with the logo as its icon.
	 */
	public function menu(): void {
		$svg = (string) file_get_contents( BITLANE_PATH . 'assets/admin/menu-icon.svg' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		add_menu_page(
			__( 'Bitlane', 'bitlane' ),
			__( 'Bitlane', 'bitlane' ),
			ConnectionController::capability(),
			self::SLUG,
			array( $this, 'render' ),
			'data:image/svg+xml;base64,' . base64_encode( $svg ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			58
		);
	}

	/**
	 * The app's root. No .wrap: the app draws its own full-width header, and
	 * WordPress would move other plugins' notices into it.
	 */
	public function render(): void {
		echo '<div id="bitlane-admin"></div>';
	}

	/**
	 * Bitlane's answer to Connect: exchanges the code, connects the site
	 * when the grant names its project, and goes back to the screen with
	 * the outcome as a notice.
	 */
	public function callback(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the OAuth state is the check.
		$page  = sanitize_key( wp_unslash( $_GET['page'] ?? '' ) );
		$oauth = sanitize_key( wp_unslash( $_GET['oauth'] ?? '' ) );
		if ( self::SLUG !== $page || 'callback' !== $oauth || ! current_user_can( ConnectionController::capability() ) ) {
			return;
		}
		$query = array_map(
			static fn( $value ) => sanitize_text_field( wp_unslash( (string) $value ) ),
			array_intersect_key( $_GET, array_flip( array( 'code', 'state', 'iss', 'error', 'error_description' ) ) )
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = $this->oauth->handle_callback( $query );
		if ( ! is_wp_error( $result ) && ! empty( $this->connection->get()['project_id'] ) ) {
			$result = $this->controller->connect_site();
		}

		set_transient(
			'bitlane_notice_' . get_current_user_id(),
			is_wp_error( $result )
				? array(
					'type'    => 'error',
					'message' => $result->get_error_message(),
				)
				: array(
					'type'    => 'success',
					'message' => __( 'Connected to Bitlane.', 'bitlane' ),
				),
			MINUTE_IN_SECONDS
		);
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * The screen's script and styles, on its page only.
	 *
	 * @param string $hook The page's hook suffix.
	 */
	public function assets( string $hook ): void {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		// build/index.js: a name ending in "min.js" would be taken for a
		// minified copy by WP-CLI's make-json, and lose its translations.
		$asset_file = BITLANE_PATH . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = require $asset_file;

		// The Media Library, to choose the widget's logo.
		wp_enqueue_media();
		wp_enqueue_script( 'bitlane-admin', BITLANE_URL . 'build/index.js', $asset['dependencies'], $asset['version'], true );
		wp_set_script_translations( 'bitlane-admin', 'bitlane', BITLANE_PATH . 'languages' );
		wp_enqueue_style( 'wp-components' );
		if ( file_exists( BITLANE_PATH . 'build/index.css' ) ) {
			wp_enqueue_style( 'bitlane-admin', BITLANE_URL . 'build/index.css', array( 'wp-components' ), $asset['version'] );
		}

		$notice = get_transient( 'bitlane_notice_' . get_current_user_id() );
		delete_transient( 'bitlane_notice_' . get_current_user_id() );
		wp_add_inline_script(
			'bitlane-admin',
			'window.bitlaneAdmin = ' . wp_json_encode(
				array(
					'notice'  => $notice ? $notice : null,
					'version' => $this->version,
					'logo'    => BITLANE_URL . 'assets/admin/logo.svg',
				)
			) . ';',
			'before'
		);
	}

	/**
	 * A notice everywhere else in wp-admin once Bitlane ended the connection.
	 */
	public function notices(): void {
		$screen = get_current_screen();
		if ( ( $screen && 'toplevel_page_' . self::SLUG === $screen->id ) || ! current_user_can( ConnectionController::capability() ) ) {
			return;
		}
		if ( empty( $this->connection->get()['revoked'] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Bitlane ended this site\'s connection, so its search stopped updating.', 'bitlane' ),
			esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ),
			esc_html__( 'Connect again', 'bitlane' )
		);
	}

	/**
	 * A Settings link on the Plugins screen.
	 *
	 * @param array<string> $links The plugin's links.
	 * @return array<string>
	 */
	public function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ), esc_html__( 'Settings', 'bitlane' ) )
		);
		return $links;
	}
}
