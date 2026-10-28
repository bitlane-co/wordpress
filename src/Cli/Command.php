<?php
/**
 * The wp bitlane commands.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Cli;

use Bitlane\WordPress\Connection\ConnectionStore;
use Bitlane\WordPress\Http\ConnectionController;
use Bitlane\WordPress\Languages\Languages;
use Bitlane\WordPress\Sync\FullSync;
use Bitlane\WordPress\Sync\Sync;
use Bitlane\WordPress\Sync\SyncState;
use WP_CLI;

/**
 * Shows the site's connection and sync, syncs everything again, and
 * disconnects. Connecting happens in wp-admin (it needs the browser for
 * Bitlane's consent page).
 */
final class Command {

	/**
	 * Builds the commands.
	 *
	 * @param Sync                 $sync       The sync.
	 * @param FullSync             $full       Runs a full sync here, with --now.
	 * @param SyncState            $state      The run.
	 * @param ConnectionStore      $connection The connection.
	 * @param ConnectionController $controller Disconnects.
	 * @param Languages            $languages  Where a full sync starts.
	 */
	public function __construct(
		private Sync $sync,
		private FullSync $full,
		private SyncState $state,
		private ConnectionStore $connection,
		private ConnectionController $controller,
		private Languages $languages,
	) {}

	/**
	 * Shows the connection and the sync.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bitlane status
	 */
	public function status(): void {
		$connection = $this->connection->get();
		if ( ! $this->connection->connected() ) {
			WP_CLI::log( 'Not connected to Bitlane. Connect in wp-admin, under Bitlane.' );
			return;
		}
		WP_CLI::log( 'Site: ' . (string) ( $connection['integration_id'] ?? '(no project chosen yet)' ) );
		$sync = $this->sync->summary();
		$rows = array_map(
			static fn( $source ) => array(
				'source'    => $source['id'],
				'full sync' => $source['running']
					? sprintf( 'running: %d of %d', $source['done'], $source['total'] )
					: ( $source['finished_at'] ? sprintf( 'done %s, %d items', wp_date( 'Y-m-d H:i', $source['finished_at'] ), $source['done'] ) : 'never' ),
			),
			$sync['sources']
		);
		WP_CLI\Utils\format_items( 'table', $rows, array( 'source', 'full sync' ) );
		WP_CLI::log( 'Queued changes: ' . $sync['queued'] );
		if ( $sync['error'] ) {
			WP_CLI::warning( sprintf( 'Last error: %s (%s)', $sync['error']['message'], wp_date( 'Y-m-d H:i', $sync['error']['at'] ) ) );
		}
	}

	/**
	 * Syncs a source again, or every chosen one.
	 *
	 * ## OPTIONS
	 *
	 * [<source>]
	 * : A source: product, post, page, a developer's own. Every chosen one without.
	 *
	 * [--now]
	 * : Sync here, batch after batch, instead of in the background.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bitlane sync
	 *     wp bitlane sync product --now
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function sync( array $args, array $assoc_args ): void {
		if ( ! $this->connection->connected() ) {
			WP_CLI::error( 'Not connected to Bitlane. Connect in wp-admin, under Bitlane.' );
		}
		$source  = $args[0] ?? null;
		$sources = wp_list_pluck( $this->sync->summary()['sources'], 'id' );
		if ( null !== $source && ! in_array( $source, $sources, true ) ) {
			WP_CLI::error( sprintf( '%s isn\'t one of the sources the setup chose: %s.', $source, implode( ', ', $sources ) ) );
		}
		$this->sync->resync( $source );
		if ( ! WP_CLI\Utils\get_flag_value( $assoc_args, 'now', false ) ) {
			WP_CLI::success( 'Syncing in the background. See wp bitlane status.' );
			return;
		}

		// Here instead: the queued first batches would only repeat this.
		$this->full->stop();
		foreach ( null === $source ? $sources : array( $source ) as $id ) {
			$run  = (string) $this->state->source( $id )['run'];
			$next = array(
				'language' => $this->languages->default(),
				'after'    => '',
			);
			while ( is_array( $next ) ) {
				$next = $this->full->step( $id, $run, $next['language'], $next['after'] );
				if ( is_wp_error( $next ) ) {
					WP_CLI::error( $next->get_error_message() );
				}
				$progress = $this->state->source( $id );
				WP_CLI::log( sprintf( '%s: %d of %d', $id, $progress['done'] ?? 0, $progress['total'] ?? 0 ) );
			}
		}
		WP_CLI::success( 'Synced.' );
	}

	/**
	 * Disconnects the site from Bitlane: its search stops updating; the
	 * engine and indexes stay in Bitlane.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Don't ask.
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Options.
	 */
	public function disconnect( array $args, array $assoc_args ): void {
		WP_CLI::confirm( 'Disconnect this site from Bitlane?', $assoc_args );
		$this->controller->disconnect();
		WP_CLI::success( 'Disconnected.' );
	}
}
