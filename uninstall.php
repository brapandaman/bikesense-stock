<?php
/**
 * Remove ledger tables and plugin options. Product catalogue rows and shop orders stay.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'bs_stock_job_labour',
	$wpdb->prefix . 'bs_stock_job_lines',
	$wpdb->prefix . 'bs_stock_jobs',
	$wpdb->prefix . 'bs_stock_movements',
	$wpdb->prefix . 'bs_stock_balances',
	$wpdb->prefix . 'bs_stock_locations',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

delete_option( 'bikesense_stock_publish' );
delete_option( 'bikesense_stock_default_low' );
delete_option( 'bikesense_stock_db_version' );
delete_option( 'bikesense_stock_rewrite' );
delete_option( 'bikesense_stock_labour_cost_rate' );
delete_option( 'bikesense_stock_labour_charge_rate' );

$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_bs_tracked','_bs_barcode','_bs_low_stock','_bs_shop_snapshot','_bs_sundry','_bs_unit','_bs_cost')"
);

$wpdb->query(
	"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('_bs_labour_cost_rate','_bs_labour_charge_rate')"
);
