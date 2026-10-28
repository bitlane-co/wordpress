<?php
/**
 * The admin screen's REST routes.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress\Http;

use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Connection\BitlaneApi;
use Bitlane\WordPress\Connection\ConnectionStore;
use Bitlane\WordPress\Connection\OAuthClient;
use Bitlane\WordPress\Languages\Languages;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Site\Site;
use Bitlane\WordPress\Storefront\Storefront;
use Bitlane\WordPress\Sync\SiteDetails;
use Bitlane\WordPress\Sync\Sync;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The routes under bitlane/v1/connection, for the admin screen only: each route needs the
 * capability to manage the store and WordPress's REST nonce, and Bitlane's
 * tokens and keys never leave the server.
 * - GET    connection: everything the screen shows;
 * - DELETE connection: disconnects the site;
 * - POST   connection/authorize: the consent page's URL;
 * - GET    connection/projects, POST connection/project: a project, for a
 *   grant on all the user's projects;
 * - GET    connection/engines: the project's engines, for the setup;
 * - POST   connection/setup: sends the setup to Bitlane;
 * - POST   connection/sync: syncs a source again (source), or everything;
 * - POST   connection/storefront: switches the widget on the storefront
 *   on or off (enabled).
 */
final class ConnectionController implements HasHooks {

	/**
	 * The routes' namespace.
	 *
	 * @var string
	 */
	public string $namespace = 'bitlane/v1';

	/**
	 * The routes' base.
	 *
	 * @var string
	 */
	public string $base = 'connection';

	/**
	 * Builds the controller.
	 *
	 * @param ConnectionStore $connection The stored connection.
	 * @param OAuthClient     $oauth      The OAuth client.
	 * @param BitlaneApi      $api        Bitlane's API.
	 * @param Site            $site       The site.
	 * @param Settings        $settings   The setup's choices.
	 * @param Sync            $sync       The sync.
	 * @param Storefront      $storefront   The widget on the storefront.
	 * @param Languages       $languages    The languages.
	 * @param SiteDetails     $site_details The site's address and name in Bitlane.
	 */
	public function __construct(
		private ConnectionStore $connection,
		private OAuthClient $oauth,
		private BitlaneApi $api,
		private Site $site,
		private Settings $settings,
		private Sync $sync,
		private Storefront $storefront,
		private Languages $languages,
		private SiteDetails $site_details,
	) {}

	/**
	 * Registers the routes when the REST API starts.
	 */
	public function hooks(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'updated_option', array( $this, 'option_changed' ) );
		add_action( 'admin_init', array( $this, 'languages_changed' ) );
		add_action( 'bitlane_send_setup', array( $this, 'resend_setup' ) );
	}

	/**
	 * A language was added or removed in WPML or Polylang (seen on the next
	 * admin page): the setup goes to Bitlane again, in the background, which
	 * makes or deletes the language's indexes; then each source syncs in
	 * full (Settings::hash()).
	 */
	public function languages_changed(): void {
		$saved = get_option( Settings::OPTION );
		if ( is_array( $saved ) && ( $saved['languages'] ?? array() ) !== $this->languages->codes() && ! empty( $this->connection->get()['integration_id'] ) && ! as_has_scheduled_action( 'bitlane_send_setup' ) ) {
			as_enqueue_async_action( 'bitlane_send_setup', array(), 'bitlane' );
		}
	}

	/**
	 * The store changed its currency or how it shows prices: the setup goes
	 * to Bitlane again, in the background, so the widget follows.
	 *
	 * @param string $option The option.
	 */
	public function option_changed( $option ): void {
		$currency = array(
			'woocommerce_currency',
			'woocommerce_currency_pos',
			'woocommerce_price_thousand_sep',
			'woocommerce_price_decimal_sep',
			'woocommerce_price_num_decimals',
		);
		if ( in_array( $option, $currency, true ) && is_array( get_option( Settings::OPTION ) ) && ! as_has_scheduled_action( 'bitlane_send_setup' ) ) {
			as_enqueue_async_action( 'bitlane_send_setup', array(), 'bitlane' );
		}
	}

	/**
	 * Sends the saved setup again, with the site's current languages,
	 * currency and price format.
	 */
	public function resend_setup(): void {
		if ( empty( $this->connection->get()['integration_id'] ) || ! is_array( get_option( Settings::OPTION ) ) ) {
			return;
		}
		$request = new WP_REST_Request( 'POST' );
		$request->set_body_params( $this->settings->get() );
		$this->setup( $request );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		register_rest_route(
			$this->namespace,
			$this->base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'status' ),
					'permission_callback' => array( $this, 'authorize' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'disconnect' ),
					'permission_callback' => array( $this, 'authorize' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->base . '/authorize',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'authorize_url' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->base . '/projects',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'projects' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->base . '/project',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'choose_project' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'project_id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->base . '/engines',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'engines' ),
				'permission_callback' => array( $this, 'authorize' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->base . '/sync',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'resync' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'source' => array( 'type' => 'string' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->base . '/storefront',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'switch_storefront' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'enabled' => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$this->base . '/setup',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'setup' ),
				'permission_callback' => array( $this, 'authorize' ),
				'args'                => array(
					'engine_id'         => array( 'type' => array( 'string', 'null' ) ),
					'sources'           => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
						'required' => true,
					),
					'outside_widget'    => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					'facets'            => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
					// Source => its sort choices, in order.
					'sorting'           => array(
						'type'                 => 'object',
						'additionalProperties' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
					'search_selector'   => array( 'type' => 'string' ),
					'brand_color'       => array( 'type' => 'string' ),
					'logo_id'           => array( 'type' => 'integer' ),
					'product_documents' => array(
						'type' => 'string',
						'enum' => Settings::PRODUCT_DOCUMENTS,
					),
				),
			)
		);
	}

	/**
	 * Store managers with WooCommerce, administrators without it.
	 */
	public function authorize(): bool {
		return current_user_can( self::capability() );
	}

	/**
	 * The capability the screen and its routes need.
	 */
	public static function capability(): string {
		return class_exists( 'WooCommerce' ) ? 'manage_woocommerce' : 'manage_options';
	}

	/**
	 * Everything the screen shows.
	 */
	public function status(): WP_REST_Response {
		$connection  = $this->connection->get();
		$integration = null;
		$error       = null;

		if ( ! empty( $connection['refresh_token'] ) && ! empty( $connection['integration_id'] ) ) {
			$details = $this->api->request( 'GET', '/api/integrations/' . rawurlencode( (string) $connection['integration_id'] ) );
			if ( is_wp_error( $details ) && 404 === ( $details->get_error_data()['status'] ?? null ) ) {
				// The site belongs to another connection now: it connects again.
				$this->connection->update( array( 'integration_id' => null ) );
			} elseif ( is_wp_error( $details ) ) {
				$error = $details->get_error_message();
			} else {
				$integration = $details;
				if ( 'ready' === ( $integration['setup_state'] ?? '' ) ) {
					$this->sync->ready( (string) ( $integration['engine_id'] ?? '' ) );
					$this->site_details->check();
				}
				$this->storefront->remember( $integration );
			}
			// A refused token may have ended the connection meanwhile.
			$connection = $this->connection->get();
		}

		$connected  = ! empty( $connection['refresh_token'] );
		$sources    = $this->site->sources();
		$storefront = $this->storefront->get();

		return new WP_REST_Response(
			array(
				'connected'     => $connected,
				'revoked'       => ! empty( $connection['revoked'] ),
				'needs_project' => $connected && empty( $connection['integration_id'] ),
				'integration'   => $integration,
				'error'         => $error,
				'app_url'       => $connected ? $this->oauth->app_url() : null,
				'site'          => array(
					'name'         => $this->site->name(),
					'url'          => $this->site->url(),
					'languages'    => $this->site->languages(),
					'multilingual' => $this->site->multilingual(),
					'currency'     => $this->site->currency(),
					'sources'      => $sources,
					'facets'       => $this->site->facets( wp_list_pluck( $sources, 'id' ) ),
					'sorting'      => $this->site->sorting(),
					'palette'      => array_values( $this->site->palette() ),
				),
				'settings'      => $this->settings->get(),
				'sync'          => $connected ? $this->sync->summary() : null,
				'storefront'    => array(
					'enabled'   => $storefront['enabled'],
					'widget_id' => '' !== $storefront['widget_id'] ? $storefront['widget_id'] : null,
				),
			),
			200
		);
	}

	/**
	 * Switches the widget on the storefront on or off.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function switch_storefront( WP_REST_Request $request ): WP_REST_Response {
		$this->storefront->enable( (bool) $request['enabled'] );
		return $this->status();
	}

	/**
	 * Syncs a source again, or everything.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function resync( WP_REST_Request $request ): WP_REST_Response {
		$this->sync->resync( $request['source'] ? sanitize_key( (string) $request['source'] ) : null );
		return $this->status();
	}

	/**
	 * The consent page's URL, to start a connection.
	 */
	public function authorize_url(): WP_REST_Response|WP_Error {
		$url = $this->oauth->authorize_url();
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		return new WP_REST_Response( array( 'authorize_url' => $url ), 200 );
	}

	/**
	 * The user's projects, for a grant on all of them.
	 */
	public function projects(): WP_REST_Response|WP_Error {
		$projects = $this->api->request( 'GET', '/api/projects' );
		if ( is_wp_error( $projects ) ) {
			return $projects;
		}
		return new WP_REST_Response(
			array_map(
				static fn( $project ) => array(
					'id'    => $project['id'],
					'title' => $project['title'] ?? $project['name'] ?? $project['id'],
				),
				$projects['data'] ?? array()
			),
			200
		);
	}

	/**
	 * Connects the site in the chosen project.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function choose_project( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connected = $this->connect_site( sanitize_text_field( (string) $request['project_id'] ) );
		if ( is_wp_error( $connected ) ) {
			return $connected;
		}
		return $this->status();
	}

	/**
	 * Connects the site in Bitlane (the grant's integration for this site,
	 * created or found), in the grant's project or the given one.
	 *
	 * @param string|null $project_id A project, for a grant on all projects.
	 */
	public function connect_site( ?string $project_id = null ): true|WP_Error {
		$body = array(
			'platform'  => 'wordpress',
			'site_url'  => $this->site->url(),
			'site_name' => $this->site->name(),
		);
		if ( $project_id ) {
			$body['project_id'] = $project_id;
		}
		$integration = $this->api->request( 'POST', '/api/integrations', $body );
		if ( is_wp_error( $integration ) ) {
			return $integration;
		}
		$this->connection->update(
			array(
				'integration_id' => $integration['id'],
				'project_id'     => $integration['project_id'] ?? $project_id,
			)
		);
		return true;
	}

	/**
	 * The project's engines, for the setup.
	 */
	public function engines(): WP_REST_Response|WP_Error {
		$project = $this->connection->get()['project_id'] ?? null;
		$engines = $this->api->request( 'GET', '/api/engines', null, $project ? array( 'project_id' => $project ) : array() );
		if ( is_wp_error( $engines ) ) {
			return $engines;
		}
		return new WP_REST_Response(
			array_map(
				static fn( $engine ) => array(
					'id'     => $engine['id'],
					'title'  => $engine['title'],
					'status' => $engine['status'],
				),
				$engines['data'] ?? array()
			),
			200
		);
	}

	/**
	 * Saves the setup and sends it to Bitlane, which sets the site up in the
	 * background: engine, indexes, keys and widget.
	 *
	 * @param WP_REST_Request $request The request.
	 */
	public function setup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$connection = $this->connection->get();
		if ( empty( $connection['integration_id'] ) ) {
			return new WP_Error( 'bitlane_not_connected', __( 'Connect the site to Bitlane first.', 'bitlane' ), array( 'status' => 409 ) );
		}

		$kinds     = wp_list_pluck( $this->site->sources(), 'kind', 'id' );
		$sources   = array_values( array_intersect( (array) $request['sources'], array_keys( $kinds ) ) );
		$outside   = array_values( array_intersect( (array) ( $request['outside_widget'] ?? array() ), $sources ) );
		$labels    = wp_list_pluck( $this->site->facets( $sources ), 'label', 'attribute' );
		$facets    = array_values( array_intersect( (array) ( $request['facets'] ?? array() ), array_keys( $labels ) ) );
		$sorting   = $this->setup_sorting( $sources, $kinds, (array) ( $request['sorting'] ?? array() ) );
		$engine_id = $request['engine_id'] ? sanitize_text_field( (string) $request['engine_id'] ) : null;
		$selector  = sanitize_text_field( (string) ( $request['search_selector'] ?? '' ) );
		$color     = (string) sanitize_hex_color( (string) ( $request['brand_color'] ?? '' ) );
		$logo_id   = absint( $request['logo_id'] ?? 0 );
		$logo_url  = Settings::logo_url( $logo_id );
		$documents = (string) ( $request['product_documents'] ?? Settings::PER_VARIATION );

		if ( ! $sources ) {
			return new WP_Error( 'bitlane_no_sources', __( 'Choose at least one kind of content to search.', 'bitlane' ), array( 'status' => 400 ) );
		}

		$languages = $this->languages->codes();
		$setup     = array(
			// Each with its own indexes, all in the store's currency.
			'languages' => array_map(
				fn( $code ) => array_filter(
					array(
						'code'     => $code,
						'currency' => $this->site->currency(),
						'format'   => $this->site->currency_format(),
					)
				),
				$languages
			),
			// Each source has its own index; the widget searches those not
			// kept for the site's own use, sorted by their choices.
			'sources'   => array_map(
				static fn( $id ) => array(
					'id'      => $id,
					'kind'    => $kinds[ $id ],
					'widget'  => ! in_array( $id, $outside, true ),
					'sorting' => $sorting[ $id ],
				),
				$sources
			),
			'facets'    => $this->setup_facets( $sources, $facets, $languages ),
		);
		if ( '' !== $selector ) {
			$setup['search_selector'] = $selector;
		}
		if ( $engine_id ) {
			$setup['engine_id'] = $engine_id;
		}
		// Bitlane uses them for a widget without colors or a logo only: the
		// widget's Appearance in Bitlane changes them after that.
		$brand = array_filter(
			array(
				'primary'  => $color,
				'logo_url' => $logo_url,
			)
		);
		if ( $brand ) {
			$setup['brand'] = $brand;
		}

		$saved = $this->api->request( 'PUT', '/api/integrations/' . rawurlencode( (string) $connection['integration_id'] ) . '/setup', $setup );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		$this->settings->save(
			array(
				'engine_id'         => $engine_id,
				'sources'           => $sources,
				'outside_widget'    => $outside,
				'facets'            => $facets,
				'sorting'           => $sorting,
				'search_selector'   => $selector,
				'brand_color'       => $color,
				'logo_id'           => '' !== $logo_url ? $logo_id : 0,
				'product_documents' => $documents,
				'languages'         => $languages,
			)
		);
		return $this->status();
	}

	/**
	 * The chosen filters as Bitlane takes them, in the order chosen (the
	 * widget shows them so), each with the post types that have it, so it's
	 * a filter of their index only, and its name in each language.
	 *
	 * @param string[] $sources   The chosen post types.
	 * @param string[] $chosen    The chosen taxonomies.
	 * @param string[] $languages The languages, the labels' keys.
	 * @return list<array{attribute: string, labels: array<string, string>, sources: list<string>}>
	 */
	private function setup_facets( array $sources, array $chosen, array $languages ): array {
		$labels = array();
		foreach ( $languages as $language ) {
			foreach ( $this->languages->in( $language, fn() => $this->facet_labels( $sources, $chosen ) ) as $attribute => $label ) {
				$labels[ $attribute ][ $language ] = $label;
			}
		}
		$offered = array_column( $this->site->facets( $sources ), 'sources', 'attribute' );
		return array_values(
			array_map(
				static fn( $attribute ) => array(
					'attribute' => (string) $attribute,
					'labels'    => $labels[ $attribute ] ?? array(),
					'sources'   => $offered[ $attribute ],
				),
				array_filter( $chosen, static fn( $attribute ) => isset( $offered[ $attribute ] ) )
			)
		);
	}

	/**
	 * Each source's sort choices: those asked for that its kind has, in their
	 * order, else its kind's defaults.
	 *
	 * @param string[]              $sources The chosen post types.
	 * @param array<string, string> $kinds   Each source's kind.
	 * @param array<string, mixed>  $chosen  Source => the choices asked for.
	 * @return array<string, list<string>>
	 */
	private function setup_sorting( array $sources, array $kinds, array $chosen ): array {
		$offered = $this->site->sorting();
		$sorting = array();
		foreach ( $sources as $source ) {
			$kind               = $offered[ $kinds[ $source ] ] ?? $offered['other'];
			$sorting[ $source ] = isset( $chosen[ $source ] )
				? array_values( array_intersect( array_map( 'strval', (array) $chosen[ $source ] ), wp_list_pluck( $kind['choices'], 'id' ) ) )
				: $kind['defaults'];
		}
		return $sorting;
	}

	/**
	 * The chosen filters' names in the current language, by attribute
	 * (WordPress names taxonomies in the language it runs in), as the site
	 * names them: each results type has its own filters in the widget, so a
	 * name two types share ("Category") needs nothing added.
	 *
	 * @param string[] $sources The chosen post types.
	 * @param string[] $chosen  The chosen taxonomies.
	 * @return array<string, string>
	 */
	private function facet_labels( array $sources, array $chosen ): array {
		$labels = array();
		foreach ( $this->site->facets( $sources ) as $facet ) {
			if ( in_array( $facet['attribute'], $chosen, true ) ) {
				$labels[ $facet['attribute'] ] = $facet['label'];
			}
		}
		return $labels;
	}

	/**
	 * Disconnects the site: Bitlane deletes its keys, and ends the grant when
	 * the site was its only one; the engine, indexes and documents stay.
	 */
	public function disconnect(): WP_REST_Response {
		$id = $this->connection->get()['integration_id'] ?? null;
		if ( $id ) {
			$this->api->request( 'DELETE', '/api/integrations/' . rawurlencode( (string) $id ) );
		}
		$this->oauth->revoke();
		$this->sync->disconnected();
		$this->storefront->forget();
		$this->site_details->forget();
		return $this->status();
	}
}
