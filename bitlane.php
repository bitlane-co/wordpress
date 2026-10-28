<?php
/**
 * Plugin Name:       Bitlane Search
 * Plugin URI:        https://bitlane.co
 * Description:       Fast search for WordPress and WooCommerce: connect to Bitlane, sync your products and posts, and add the search widget.
 * Version:           0.5.1
 * Requires at least: 6.6
 * Requires PHP:      8.2
 * Author:            Bitlane
 * Author URI:        https://bitlane.co
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bitlane
 * Domain Path:       /languages
 * Update URI:        https://cdn.bitlane.co/wordpress/
 * WC requires at least: 9.0
 *
 * @package Bitlane
 */

defined( 'ABSPATH' ) || exit;

define( 'BITLANE_VERSION', '0.5.1' );
define( 'BITLANE_FILE', __FILE__ );
define( 'BITLANE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BITLANE_URL', plugin_dir_url( __FILE__ ) );

require_once BITLANE_PATH . 'vendor/autoload.php';
// Action Scheduler loads the newest of the copies plugins bring (WooCommerce's
// included), so ours only runs when it's the newest or the only one.
require_once BITLANE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';

Bitlane\WordPress\Plugin::instance( BITLANE_VERSION );
