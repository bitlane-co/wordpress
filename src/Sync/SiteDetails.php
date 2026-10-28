<?php
/**
 * The site's address and name in Bitlane.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Sync;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Connection\BitlaneApi;
use Bitlane\WordPress\Connection\ConnectionStore;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Site\Site;

/**
 * A site moved to another domain or path keeps its site in Bitlane, and its
 * documents' links follow. The address and name are compared with what was
 * last seen (the option bitlane_site_details) when Settings › General saves
 * them, when the admin screen loads, and in the daily check, since a
 * migration (a plugin, a search-replace, WP_HOME) changes them without a hook:
 * - a new address syncs every source again (their links);
 * - the action bitlane_site_details sends both to PATCH /api/integrations/:id,
 *   retried with backoff; an address another site of the project has is
 *   refused (409) and not retried. Connecting with the new address would
 *   make another site.
 */
final class SiteDetails implements HasHooks {

	/**
	 * Builds the details.
	 *
	 * @param Site            $site       The site's address and name.
	 * @param Settings        $settings   The setup's sources.
	 * @param ConnectionStore $connection The site's integration.
	 * @param BitlaneApi      $api        The API.
	 * @param Queue           $queue      Whether the site syncs.
	 * @param FullSync        $full       The full syncs.
	 */
	public function __construct(
		private Site $site,
		private Settings $settings,
		private ConnectionStore $connection,
		private BitlaneApi $api,
		private Queue $queue,
		private FullSync $full,
	) {}

	/**
	 * Settings › General, the daily check, and the action.
	 */
	public function hooks(): void {
		add_action( 'updated_option', array( $this, 'option_changed' ) );
		add_action( 'bitlane_check', array( $this, 'check' ) );
		add_action( 'bitlane_site_details', array( $this, 'send' ) );
	}

	/**
	 * The address (home) or the name saved in Settings › General.
	 *
	 * @param string $option The option.
	 */
	public function option_changed( $option ): void {
		if ( in_array( $option, array( 'home', 'blogname' ), true ) ) {
			$this->check();
		}
	}

	/**
	 * Resyncs and sends what changed since it was last seen; the first look
	 * only notes it (connecting sent it).
	 */
	public function check(): void {
		if ( ! $this->queue->active() ) {
			return;
		}
		$now  = $this->details();
		$kept = get_option( 'bitlane_site_details', array() );
		if ( ! is_array( $kept ) || ! isset( $kept['links'] ) ) {
			update_option( 'bitlane_site_details', $now + array( 'links' => $now['site_url'] ), false );
			return;
		}
		if ( $kept['links'] !== $now['site_url'] ) {
			array_map( array( $this->full, 'start' ), $this->settings->get()['sources'] );
			$kept['links'] = $now['site_url'];
			update_option( 'bitlane_site_details', $kept, false );
		}
		$sent = array(
			'site_url'  => (string) ( $kept['site_url'] ?? '' ),
			'site_name' => (string) ( $kept['site_name'] ?? '' ),
		);
		if ( $sent !== $now && ! as_has_scheduled_action( 'bitlane_site_details' ) ) {
			as_enqueue_async_action( 'bitlane_site_details', array( 0 ), 'bitlane' );
		}
	}

	/**
	 * The action: Bitlane gets the address and name as they are now.
	 *
	 * @param int $attempt Attempts so far.
	 */
	public function send( int $attempt = 0 ): void {
		$id = $this->connection->get()['integration_id'] ?? null;
		if ( ! $id ) {
			return;
		}
		$now  = $this->details();
		$sent = $this->api->request( 'PATCH', '/api/integrations/' . rawurlencode( (string) $id ), $now );
		if ( ! is_wp_error( $sent ) ) {
			$kept = get_option( 'bitlane_site_details', array() );
			update_option( 'bitlane_site_details', $now + ( is_array( $kept ) ? $kept : array() ), false );
			return;
		}
		$delay = Retry::after( $sent, $attempt );
		if ( null !== $delay ) {
			as_schedule_single_action( time() + $delay, 'bitlane_site_details', array( $attempt + 1 ), 'bitlane' );
		}
	}

	/**
	 * The site disconnected: what was seen goes, and nothing more is sent.
	 */
	public function forget(): void {
		as_unschedule_all_actions( 'bitlane_site_details' );
		delete_option( 'bitlane_site_details' );
	}

	/**
	 * The site's address and name, as the setup sends them.
	 *
	 * @return array{site_url: string, site_name: string}
	 */
	private function details(): array {
		return array(
			'site_url'  => $this->site->url(),
			'site_name' => $this->site->name(),
		);
	}
}
