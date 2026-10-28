<?php
/**
 * The plugin: its parts, wired together.
 *
 * @package Bitlane
 */

namespace Bitlane\WordPress;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use Bitlane\WordPress\Admin\AdminPage;
use Bitlane\WordPress\Admin\Updates;
use Bitlane\WordPress\Cli\Command;
use Bitlane\WordPress\Concerns\HasHooks;
use Bitlane\WordPress\Connection\BitlaneApi;
use Bitlane\WordPress\Connection\ConnectionStore;
use Bitlane\WordPress\Connection\OAuthClient;
use Bitlane\WordPress\Documents\OtherDocument;
use Bitlane\WordPress\Documents\ProductDocuments;
use Bitlane\WordPress\Http\ConnectionController;
use Bitlane\WordPress\Languages\Languages;
use Bitlane\WordPress\Site\Settings;
use Bitlane\WordPress\Site\Site;
use Bitlane\WordPress\Sources\Sources;
use Bitlane\WordPress\Storefront\CartAdditions;
use Bitlane\WordPress\Storefront\Purchases;
use Bitlane\WordPress\Storefront\Refunds;
use Bitlane\WordPress\Storefront\SiteEvents;
use Bitlane\WordPress\Storefront\Storefront;
use Bitlane\WordPress\Support\Crypto;
use Bitlane\WordPress\Support\Http;
use Bitlane\WordPress\Support\Lock;
use Bitlane\WordPress\Sync\Check;
use Bitlane\WordPress\Sync\Credentials;
use Bitlane\WordPress\Sync\FullSync;
use Bitlane\WordPress\Sync\Indexer;
use Bitlane\WordPress\Sync\Meilisearch;
use Bitlane\WordPress\Sync\Queue;
use Bitlane\WordPress\Sync\SiteDetails;
use Bitlane\WordPress\Sync\Sync;
use Bitlane\WordPress\Sync\SyncState;

/**
 * Builds the plugin's parts and adds their hooks: each part that hooks into
 * WordPress implements HasHooks. There's one, from Plugin::instance().
 */
final class Plugin implements HasHooks {

	/**
	 * The plugin, once built.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * The admin screen's REST routes.
	 *
	 * @var ConnectionController
	 */
	private ConnectionController $controller;

	/**
	 * The Bitlane menu and screen.
	 *
	 * @var AdminPage
	 */
	private AdminPage $admin;

	/**
	 * The full syncs.
	 *
	 * @var FullSync
	 */
	private FullSync $full;

	/**
	 * The changes' queue.
	 *
	 * @var Queue
	 */
	private Queue $queue;

	/**
	 * The daily check of each index.
	 *
	 * @var Check
	 */
	private Check $check;

	/**
	 * The widget on the storefront.
	 *
	 * @var Storefront
	 */
	private Storefront $storefront;

	/**
	 * The site's address and name in Bitlane.
	 *
	 * @var SiteDetails
	 */
	private SiteDetails $site_details;

	/**
	 * Additions to the cart, sent to Bitlane.
	 *
	 * @var CartAdditions
	 */
	private CartAdditions $cart_additions;

	/**
	 * Paid orders, sent to Bitlane.
	 *
	 * @var Purchases
	 */
	private Purchases $purchases;

	/**
	 * Refunds of reported orders, sent to Bitlane.
	 *
	 * @var Refunds
	 */
	private Refunds $refunds;

	/**
	 * The wp bitlane commands.
	 *
	 * @var Command
	 */
	private Command $command;

	/**
	 * The plugin's updates, from Bitlane's CDN.
	 *
	 * @var Updates
	 */
	private Updates $updates;

	/**
	 * The plugin, built with its hooks added on the first call; later calls
	 * return the same one.
	 *
	 * @param string $version The plugin's version (BITLANE_VERSION).
	 */
	public static function instance( string $version = BITLANE_VERSION ): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self( $version );
			self::$instance->hooks();
		}
		return self::$instance;
	}

	/**
	 * Builds the plugin and its parts.
	 *
	 * @param string $version The plugin's version (BITLANE_VERSION).
	 */
	private function __construct( string $version ) {
		$http       = new Http();
		$crypto     = new Crypto();
		$connection = new ConnectionStore( $crypto );
		$oauth      = new OAuthClient( $connection, $http, new Lock(), $version );
		$api        = new BitlaneApi( $oauth, $connection, $http );
		$languages  = new Languages();
		$sources    = new Sources( new ProductDocuments(), new OtherDocument(), $languages );
		$site       = new Site( $sources, $languages );
		$settings   = new Settings( $site );

		$credentials = new Credentials( $api, $connection, $crypto );
		$meilisearch = new Meilisearch( $http );
		$state       = new SyncState();
		$indexer     = new Indexer( $sources, $settings, $languages, $credentials, $meilisearch );
		$this->full  = new FullSync( $indexer, $sources, $settings, $languages, $state, $credentials, $meilisearch );
		$this->queue = new Queue( $sources, $settings, $indexer, $state, $connection, $this->full );
		$this->check = new Check( $sources, $settings, $languages, $state, $credentials, $meilisearch, $this->full );
		$sync        = new Sync( $this->full, $this->queue, $state, $settings, $credentials, $this->check );

		$events               = new SiteEvents( $api, $connection, $languages );
		$this->storefront     = new Storefront( $api, $connection );
		$this->cart_additions = new CartAdditions( $events, $settings );
		$this->purchases      = new Purchases( $events, $settings );
		$this->refunds        = new Refunds( $events, $settings );

		$this->site_details = new SiteDetails( $site, $settings, $connection, $api, $this->queue, $this->full );
		$this->controller   = new ConnectionController( $connection, $oauth, $api, $site, $settings, $sync, $this->storefront, $languages, $this->site_details );
		$this->admin        = new AdminPage( $connection, $oauth, $this->controller, $version );
		$this->command      = new Command( $sync, $this->full, $state, $connection, $this->controller, $languages );
		$this->updates      = new Updates( $http, $version );
	}

	/**
	 * No copies.
	 */
	private function __clone() {
	}

	/**
	 * Adds the parts' hooks, the commands, the translations, and
	 * WooCommerce's compatibility declarations.
	 */
	public function hooks(): void {
		$this->controller->hooks();
		$this->admin->hooks();
		$this->full->hooks();
		$this->queue->hooks();
		$this->check->hooks();
		$this->site_details->hooks();
		$this->storefront->hooks();
		$this->cart_additions->hooks();
		$this->purchases->hooks();
		$this->refunds->hooks();
		$this->updates->hooks();

		add_action( 'cli_init', array( $this, 'commands' ) );
		add_action( 'init', array( $this, 'translations' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
	}

	/**
	 * Adds the wp bitlane commands, once WP-CLI is ready for them.
	 */
	public function commands(): void {
		\WP_CLI::add_command( 'bitlane', $this->command );
	}

	/**
	 * Loads the plugin's translations.
	 */
	public function translations(): void {
		load_plugin_textdomain( 'bitlane', false, dirname( plugin_basename( BITLANE_FILE ) ) . '/languages' );
	}

	/**
	 * Declares the plugin works with WooCommerce's order tables (HPOS) and
	 * the Cart and Checkout blocks.
	 */
	public function declare_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', BITLANE_FILE, true );
			FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BITLANE_FILE, true );
		}
	}
}
