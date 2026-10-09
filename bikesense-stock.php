<?php
/**
 * Plugin Name: Bike Sense Stock
 * Description: Private stock taking for Bike Sense. Phone, computer, and bots read one ledger, and counted parts can update the shop.
 * Version: 1.0.0
 * Author: Bike Sense
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Text Domain: bikesense-stock
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'BS_STOCK_VERSION', '1.0.0' );
define( 'BS_STOCK_FILE', __FILE__ );
define( 'BS_STOCK_DIR', plugin_dir_path( __FILE__ ) );
define( 'BS_STOCK_URL', plugin_dir_url( __FILE__ ) );

require_once BS_STOCK_DIR . 'includes/class-install.php';
require_once BS_STOCK_DIR . 'includes/class-ledger.php';
require_once BS_STOCK_DIR . 'includes/class-sync.php';
require_once BS_STOCK_DIR . 'includes/class-api.php';
require_once BS_STOCK_DIR . 'includes/class-abilities.php';
require_once BS_STOCK_DIR . 'includes/class-page.php';
require_once BS_STOCK_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'BS_Stock_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BS_Stock_Install', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'BS_Stock_Plugin', 'boot' ) );
add_action( 'wp_abilities_api_categories_init', array( 'BS_Stock_Abilities', 'register_category' ) );
add_action( 'wp_abilities_api_init', array( 'BS_Stock_Abilities', 'register' ) );
