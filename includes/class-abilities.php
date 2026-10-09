<?php
/**
 * Read-only abilities for Cursor and the other site bots.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_Abilities {

	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			'bikesense-stock',
			array(
				'label'       => __( 'Bike Sense stock', 'bikesense-stock' ),
				'description' => __( 'Read quantities, low stock, and the movement ledger.', 'bikesense-stock' ),
			)
		);
	}

	public static function register() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$read = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);

		self::add(
			'bikesense-stock/search',
			__( 'Search stock', 'bikesense-stock' ),
			__( 'Search Bike Sense stock by part number, barcode, or product name. Returns the quantity at each location. Does not change stock.', 'bikesense-stock' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'query' => array(
						'type'        => 'string',
						'description' => __( 'Part number, barcode, or product name.', 'bikesense-stock' ),
					),
				),
				'required'             => array( 'query' ),
				'additionalProperties' => true,
			),
			array( __CLASS__, 'search' ),
			$read
		);

		self::add(
			'bikesense-stock/get',
			__( 'Get stock for one part', 'bikesense-stock' ),
			__( 'Get one Bike Sense part by SKU, barcode, or product id, including the quantity at each location. Does not change stock.', 'bikesense-stock' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'sku' => array(
						'type'        => 'string',
						'description' => __( 'Part number or barcode.', 'bikesense-stock' ),
					),
					'id'  => array(
						'type'        => 'integer',
						'description' => __( 'WooCommerce product id.', 'bikesense-stock' ),
					),
				),
				'additionalProperties' => true,
			),
			array( __CLASS__, 'get_part' ),
			$read
		);

		self::add(
			'bikesense-stock/low',
			__( 'List low stock', 'bikesense-stock' ),
			__( 'List counted Bike Sense parts at or below their low-stock threshold. Does not change stock.', 'bikesense-stock' ),
			array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => true,
			),
			array( __CLASS__, 'low' ),
			$read
		);

		self::add(
			'bikesense-stock/movements',
			__( 'Read stock movements', 'bikesense-stock' ),
			__( 'Read recent Bike Sense stock movements. Filter with a product id or SKU when you need one part. Does not change stock.', 'bikesense-stock' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'product_id' => array(
						'type'        => 'integer',
						'description' => __( 'WooCommerce product id.', 'bikesense-stock' ),
					),
					'sku'        => array(
						'type'        => 'string',
						'description' => __( 'Part number.', 'bikesense-stock' ),
					),
					'limit'      => array(
						'type'        => 'integer',
						'description' => __( 'How many movements to return, up to 100.', 'bikesense-stock' ),
					),
				),
				'additionalProperties' => true,
			),
			array( __CLASS__, 'movements' ),
			$read
		);
	}

	/**
	 * @param string   $name Ability name.
	 * @param string   $label Label.
	 * @param string   $description Description.
	 * @param array    $input_schema Input schema.
	 * @param callable $callback Execute callback.
	 * @param array    $annotations Annotations.
	 */
	private static function add( $name, $label, $description, $input_schema, $callback, $annotations ) {
		wp_register_ability(
			$name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => 'bikesense-stock',
				'input_schema'        => $input_schema,
				'output_schema'       => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'execute_callback'    => $callback,
				'permission_callback' => array( __CLASS__, 'can_read' ),
				'meta'                => array(
					'public'      => true,
					'annotations' => $annotations,
				),
			)
		);
	}

	/**
	 * @return bool|WP_Error
	 */
	public static function can_read() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		return new WP_Error( 'bs_stock_forbidden', __( 'You cannot read Bike Sense stock.', 'bikesense-stock' ) );
	}

	/**
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function search( $input ) {
		$input = is_array( $input ) ? $input : array();
		$query = isset( $input['query'] ) ? $input['query'] : '';
		return BS_Stock_Ledger::search( $query );
	}

	/**
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function get_part( $input ) {
		$input = is_array( $input ) ? $input : array();
		$id    = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$sku   = isset( $input['sku'] ) ? (string) $input['sku'] : '';
		if ( ! $id && '' === trim( $sku ) ) {
			return new WP_Error( 'bs_stock_query', __( 'Pass a part number or a product id.', 'bikesense-stock' ) );
		}

		return BS_Stock_Ledger::get_product( $id, $sku );
	}

	/**
	 * @return array
	 */
	public static function low() {
		return BS_Stock_Ledger::low_stock();
	}

	/**
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function movements( $input ) {
		$input      = is_array( $input ) ? $input : array();
		$product_id = isset( $input['product_id'] ) ? (int) $input['product_id'] : 0;
		if ( ! $product_id && ! empty( $input['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = (int) wc_get_product_id_by_sku( $input['sku'] );
			if ( ! $product_id ) {
				return new WP_Error( 'bs_stock_product', __( 'That part was not found.', 'bikesense-stock' ) );
			}
		}

		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : 50;
		return BS_Stock_Ledger::movements( $product_id, $limit );
	}
}
