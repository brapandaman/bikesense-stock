<?php
/**
 * Logged-in REST API. Reads work with an application password. Writes need the stock page nonce.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_API {

	const NAMESPACE = 'bikesense-stock/v1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/product',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'product' ),
					'permission_callback' => array( __CLASS__, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post_product' ),
					'permission_callback' => array( __CLASS__, 'can_write' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/low',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'low' ),
				'permission_callback' => array( __CLASS__, 'can_read' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/movements',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'movements' ),
					'permission_callback' => array( __CLASS__, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post_movement' ),
					'permission_callback' => array( __CLASS__, 'can_write' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_settings' ),
					'permission_callback' => array( __CLASS__, 'can_read' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'post_settings' ),
					'permission_callback' => array( __CLASS__, 'can_write' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/locations',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_location' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/locations/update',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'post_location_update' ),
				'permission_callback' => array( __CLASS__, 'can_write' ),
			)
		);
	}

	/**
	 * Shop managers and administrators, including application-password clients.
	 *
	 * @return bool
	 */
	public static function can_read() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * The stock page sends a REST nonce. Application passwords do not, so bots cannot change quantities.
	 *
	 * @return bool
	 */
	public static function can_write() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}

		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function search( $request ) {
		$result = BS_Stock_Ledger::search( $request->get_param( 'q' ), $request->get_param( 'limit' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function product( $request ) {
		$result = BS_Stock_Ledger::get_product( (int) $request->get_param( 'id' ), (string) $request->get_param( 'sku' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function low() {
		return rest_ensure_response( BS_Stock_Ledger::low_stock() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function movements( $request ) {
		return rest_ensure_response(
			BS_Stock_Ledger::movements(
				(int) $request->get_param( 'product_id' ),
				$request->get_param( 'limit' ) ? (int) $request->get_param( 'limit' ) : 50
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		return rest_ensure_response( BS_Stock_Sync::settings() );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_settings( $request ) {
		$params = self::params( $request );
		$result = BS_Stock_Sync::update_settings(
			! empty( $params['publish'] ),
			isset( $params['default_low'] ) ? $params['default_low'] : get_option( 'bikesense_stock_default_low', 1 )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_movement( $request ) {
		$params = self::params( $request );
		$type   = isset( $params['type'] ) ? sanitize_key( $params['type'] ) : '';
		if ( ! in_array( $type, array( 'in', 'out', 'count', 'transfer' ), true ) ) {
			return new WP_Error( 'bs_stock_type', 'Choose goods in, goods out, count, or transfer.', array( 'status' => 400 ) );
		}

		$result = BS_Stock_Ledger::record(
			array(
				'type'           => $type,
				'product_id'     => isset( $params['product_id'] ) ? (int) $params['product_id'] : 0,
				'location_id'    => isset( $params['location_id'] ) ? (int) $params['location_id'] : 0,
				'to_location_id' => isset( $params['to_location_id'] ) ? (int) $params['to_location_id'] : 0,
				'qty'            => isset( $params['qty'] ) ? $params['qty'] : null,
				'note'           => isset( $params['note'] ) ? $params['note'] : '',
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_location( $request ) {
		$params = self::params( $request );
		$result = BS_Stock_Ledger::add_location(
			isset( $params['name'] ) ? $params['name'] : '',
			isset( $params['code'] ) ? $params['code'] : '',
			self::flag( isset( $params['is_sellable'] ) ? $params['is_sellable'] : true )
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_location_update( $request ) {
		$params = self::params( $request );
		$fields = array();
		foreach ( array( 'name', 'code', 'is_sellable', 'is_active' ) as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$fields[ $key ] = in_array( $key, array( 'is_sellable', 'is_active' ), true ) ? self::flag( $params[ $key ] ) : $params[ $key ];
			}
		}

		$result = BS_Stock_Ledger::update_location( isset( $params['id'] ) ? (int) $params['id'] : 0, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function post_product( $request ) {
		$params  = self::params( $request );
		$barcode = array_key_exists( 'barcode', $params ) ? $params['barcode'] : null;
		$low     = null;
		if ( array_key_exists( 'low_stock', $params ) ) {
			$low = ( null === $params['low_stock'] ) ? '' : $params['low_stock'];
		}

		$result = BS_Stock_Ledger::update_product_meta(
			isset( $params['product_id'] ) ? (int) $params['product_id'] : 0,
			$barcode,
			$low
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( $result );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function params( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = $request->get_body_params();
		}
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		return $params;
	}

	/**
	 * @param mixed $value Flag value.
	 * @return bool
	 */
	private static function flag( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}
}
