<?php
/**
 * Removes what the plugin stored when it's deleted. The site's engine,
 * indexes and documents stay in Bitlane (disconnect it there).
 *
 * @package Bitlane
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bitlane_connection' );
delete_option( 'bitlane_settings' );
delete_option( 'bitlane_storefront' );
delete_option( 'bitlane_sync' );
delete_option( 'bitlane_site_details' );
