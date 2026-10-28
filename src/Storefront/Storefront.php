<?php
/**
 * The search widget on the storefront.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Storefront;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Connection\BitlaneApi;
use Bitlane\WordPress\Connection\ConnectionStore;

/**
 * Prints the widget's snippet on every page (docs/wordpress-plan.md,
 * "Storefront"): window.BitlaneWidgetConfig, the queue for Bitlane.track()
 * calls made before the loader has run, and the loader as a script module.
 * Pages never call Bitlane: the site's active widget and its loader are kept
 * in the autoloaded option bitlane_storefront, read again whenever the admin
 * screen loads and every hour. The merchant can switch it off on the admin
 * screen, developers per page with bitlane_show_widget. With the WP Consent
 * API, tracking waits for consent to statistics. Google Consent Mode needs
 * nothing here: the widget follows it from window.dataLayer.
 */
final class Storefront implements HasHooks {

	/**
	 * Builds the storefront.
	 *
	 * @param BitlaneApi      $api        The API, for the hourly refresh.
	 * @param ConnectionStore $connection The connection, for the site's id.
	 */
	public function __construct(
		private BitlaneApi $api,
		private ConnectionStore $connection,
	) {}

	/**
	 * The snippet, the refresh, and the Consent API's registration.
	 */
	public function hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'bitlane_refresh_widget', array( $this, 'refresh' ) );
		add_filter( 'wp_consent_api_registered_' . plugin_basename( BITLANE_FILE ), '__return_true' );
	}

	/**
	 * What's kept: the merchant's switch and the site's active widget.
	 *
	 * @return array{enabled: bool, widget_id: string, loader_url: string}
	 */
	public function get(): array {
		$stored = get_option( 'bitlane_storefront', array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'enabled'    => (bool) ( $stored['enabled'] ?? true ),
			'widget_id'  => (string) ( $stored['widget_id'] ?? '' ),
			'loader_url' => (string) ( $stored['loader_url'] ?? '' ),
		);
	}

	/**
	 * Switches the widget on the storefront on or off.
	 *
	 * @param bool $enabled On.
	 */
	public function enable( bool $enabled ): void {
		$this->save( array( 'enabled' => $enabled ) );
	}

	/**
	 * Keeps the site's first active widget from Bitlane's integration
	 * details, with the loader from its install snippet, and refreshes it
	 * every hour from then on.
	 *
	 * @param array<string, mixed> $integration GET /api/integrations/:id.
	 */
	public function remember( array $integration ): void {
		$widget_id  = '';
		$loader_url = '';
		foreach ( (array) ( $integration['widgets'] ?? array() ) as $widget ) {
			if ( 'active' === ( $widget['status'] ?? '' ) && preg_match( '/<script[^>]+src="([^"]+)"/', (string) ( $widget['install_snippet'] ?? '' ), $match ) ) {
				$widget_id  = (string) $widget['id'];
				$loader_url = esc_url_raw( $match[1] );
				break;
			}
		}
		$this->save(
			array(
				'widget_id'  => $widget_id,
				'loader_url' => $loader_url,
			)
		);

		if ( ! as_has_scheduled_action( 'bitlane_refresh_widget', array(), 'bitlane' ) ) {
			as_schedule_recurring_action( time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'bitlane_refresh_widget', array(), 'bitlane', true );
		}
	}

	/**
	 * The hourly refresh: the widget may have been switched off or on in
	 * Bitlane. Bitlane being unreachable keeps what's known.
	 */
	public function refresh(): void {
		$id = $this->connection->get()['integration_id'] ?? null;
		if ( ! $id ) {
			$this->forget();
			return;
		}
		$integration = $this->api->request( 'GET', '/api/integrations/' . rawurlencode( (string) $id ) );
		if ( ! is_wp_error( $integration ) ) {
			$this->remember( $integration );
		} elseif ( 404 === ( $integration->get_error_data()['status'] ?? null ) || ! $this->connection->connected() ) {
			$this->forget();
		}
	}

	/**
	 * The site disconnected: no widget, and no refresh. The switch stays.
	 */
	public function forget(): void {
		$this->save(
			array(
				'widget_id'  => '',
				'loader_url' => '',
			)
		);
		as_unschedule_all_actions( 'bitlane_refresh_widget' );
	}

	/**
	 * Whether this page gets the widget: switched on, with an active widget,
	 * and not left out by bitlane_show_widget.
	 */
	public function active(): bool {
		$stored = $this->get();
		/**
		 * Whether the page gets the search widget, and with it Bitlane.track().
		 *
		 * @param bool $show Show it.
		 */
		return $stored['enabled'] && '' !== $stored['widget_id'] && '' !== $stored['loader_url'] && (bool) apply_filters( 'bitlane_show_widget', true );
	}

	/**
	 * The snippet: the config and Bitlane.track()'s queue in the head, the
	 * loader as a module, which runs once the page is parsed. With the
	 * Consent API, tracking starts off, and a footer script, which runs
	 * before the loader, follows consent to statistics: Bitlane.consent()
	 * once the loader has run, the config before.
	 */
	public function enqueue(): void {
		if ( ! $this->active() ) {
			return;
		}
		$stored  = $this->get();
		$config  = array( 'id' => $stored['widget_id'] );
		$consent = function_exists( 'wp_has_consent' );
		if ( $consent ) {
			$config['tracking'] = false;
		}

		wp_register_script( 'bitlane-widget', false, array(), BITLANE_VERSION, false );
		wp_add_inline_script(
			'bitlane-widget',
			'window.BitlaneWidgetConfig = ' . wp_json_encode( $config ) . ";\n" .
			'window.Bitlane = window.Bitlane || { q: [], track: function () { this.q.push(arguments) } };'
		);
		wp_enqueue_script( 'bitlane-widget' );
		wp_enqueue_script_module( 'bitlane-widget-loader', $stored['loader_url'], array(), null );

		if ( ! $consent ) {
			return;
		}
		wp_register_script( 'bitlane-consent', false, array( 'bitlane-widget', 'wp-consent-api' ), BITLANE_VERSION, true );
		wp_add_inline_script(
			'bitlane-consent',
			'(function () {
	function consent(granted) {
		if (window.Bitlane && window.Bitlane.consent) {
			window.Bitlane.consent(granted);
		} else {
			window.BitlaneWidgetConfig.tracking = granted;
		}
	}
	consent(wp_has_consent("statistics"));
	// Cookie banners may define the consent type (opt-in, opt-out) only later.
	document.addEventListener("wp_consent_type_defined", function () {
		consent(wp_has_consent("statistics"));
	});
	document.addEventListener("wp_listen_for_consent_change", function (event) {
		if (event.detail && event.detail.statistics) {
			consent("allow" === event.detail.statistics);
		}
	});
})();'
		);
		wp_enqueue_script( 'bitlane-consent' );
	}

	/**
	 * Merges changes into what's kept.
	 *
	 * @param array<string, mixed> $changes The changes.
	 */
	private function save( array $changes ): void {
		update_option( 'bitlane_storefront', array_merge( $this->get(), $changes ), true );
	}
}
