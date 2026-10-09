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
				'description' => __( 'Read quantities, low stock, the movement ledger, and workshop job cards.', 'bikesense-stock' ),
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

		self::add(
			'bikesense-stock/jobs',
			__( 'List workshop jobs', 'bikesense-stock' ),
			__( 'List Bike Sense workshop job cards, newest first. Filter by status (open, in_progress, waiting_parts, completed, invoiced, cancelled, or active for jobs being worked on) and search by customer, phone, registration, VIN, bike, or job number. Does not change anything.', 'bikesense-stock' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'status' => array(
						'type'        => 'string',
						'description' => __( 'Job status, active, or empty for all.', 'bikesense-stock' ),
					),
					'q'      => array(
						'type'        => 'string',
						'description' => __( 'Customer name, phone, registration, VIN, bike, or job number such as JC-0007.', 'bikesense-stock' ),
					),
					'limit'  => array(
						'type'        => 'integer',
						'description' => __( 'How many jobs to return, up to 100.', 'bikesense-stock' ),
					),
				),
				'additionalProperties' => true,
			),
			array( __CLASS__, 'jobs' ),
			$read
		);

		self::add(
			'bikesense-stock/job',
			__( 'Get one workshop job', 'bikesense-stock' ),
			__( 'Get one Bike Sense job card by job number (JC-0007) or id: customer, bike, status, parts and sundries used, labour, costing, stock movements, and a plain-English summary to read back when the job is finished. Does not change anything.', 'bikesense-stock' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'number' => array(
						'type'        => 'string',
						'description' => __( 'Job number such as JC-0007.', 'bikesense-stock' ),
					),
					'id'     => array(
						'type'        => 'integer',
						'description' => __( 'Job id.', 'bikesense-stock' ),
					),
				),
				'additionalProperties' => true,
			),
			array( __CLASS__, 'job' ),
			$read
		);

		self::add(
			'bikesense-stock/job-costing',
			__( 'Workshop job costing', 'bikesense-stock' ),
			__( 'Totals for Bike Sense workshop jobs in a date range: job count, parts, sundries, and labour cost and charge, gross profit, and margin. Jobs are dated by completion, or by opening when not completed. Cancelled jobs are left out unless status is all. Does not change anything.', 'bikesense-stock' ),
			array(
				'type'       => 'object',
				'properties' => array(
					'from'   => array(
						'type'        => 'string',
						'description' => __( 'Start date YYYY-MM-DD. Defaults to the first of this month.', 'bikesense-stock' ),
					),
					'to'     => array(
						'type'        => 'string',
						'description' => __( 'End date YYYY-MM-DD. Defaults to today.', 'bikesense-stock' ),
					),
					'status' => array(
						'type'        => 'string',
						'description' => __( 'Optional job status, active, or all.', 'bikesense-stock' ),
					),
				),
				'additionalProperties' => true,
			),
			array( __CLASS__, 'job_costing' ),
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

	/**
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function jobs( $input ) {
		$input = is_array( $input ) ? $input : array();
		return BS_Stock_Jobs::list_jobs(
			isset( $input['status'] ) && is_scalar( $input['status'] ) ? (string) $input['status'] : '',
			isset( $input['q'] ) && is_scalar( $input['q'] ) ? (string) $input['q'] : '',
			isset( $input['limit'] ) ? (int) $input['limit'] : 30
		);
	}

	/**
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function job( $input ) {
		$input  = is_array( $input ) ? $input : array();
		$id     = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$number = isset( $input['number'] ) && is_scalar( $input['number'] ) ? (string) $input['number'] : '';
		if ( ! $id && '' === trim( $number ) ) {
			return new WP_Error( 'bs_stock_query', __( 'Pass a job number such as JC-0007, or a job id.', 'bikesense-stock' ) );
		}

		return BS_Stock_Jobs::get( $id, $number );
	}

	/**
	 * @param mixed $input Ability input.
	 * @return array|WP_Error
	 */
	public static function job_costing( $input ) {
		$input = is_array( $input ) ? $input : array();
		return BS_Stock_Jobs::costing_report(
			isset( $input['from'] ) && is_scalar( $input['from'] ) ? (string) $input['from'] : '',
			isset( $input['to'] ) && is_scalar( $input['to'] ) ? (string) $input['to'] : '',
			isset( $input['status'] ) && is_scalar( $input['status'] ) ? (string) $input['status'] : ''
		);
	}
}
