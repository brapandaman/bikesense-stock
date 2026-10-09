<?php
/**
 * Locations, balances, and the stock movement ledger.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_Ledger {

	const META_TRACKED  = '_bs_tracked';
	const META_BARCODE  = '_bs_barcode';
	const META_LOW      = '_bs_low_stock';
	const META_SNAPSHOT = '_bs_shop_snapshot';
	const META_SUNDRY   = '_bs_sundry';
	const META_UNIT     = '_bs_unit';
	const META_COST     = '_bs_cost';

	/**
	 * @return string
	 */
	public static function locations_table() {
		global $wpdb;
		return $wpdb->prefix . 'bs_stock_locations';
	}

	/**
	 * @return string
	 */
	public static function balances_table() {
		global $wpdb;
		return $wpdb->prefix . 'bs_stock_balances';
	}

	/**
	 * @return string
	 */
	public static function movements_table() {
		global $wpdb;
		return $wpdb->prefix . 'bs_stock_movements';
	}

	/**
	 * Sundries keep two decimals. Other parts follow WooCommerce, which rounds to whole units by default.
	 *
	 * @param mixed $qty Raw quantity.
	 * @param bool  $decimal Keep decimals.
	 * @return float|null
	 */
	public static function normalize_qty( $qty, $decimal = false ) {
		if ( ! is_numeric( $qty ) ) {
			return null;
		}

		$qty = (float) $qty;
		if ( $decimal ) {
			return round( $qty, 2 );
		}
		if ( function_exists( 'wc_stock_amount' ) ) {
			$qty = (float) wc_stock_amount( $qty );
		}

		return $qty;
	}

	/**
	 * @param int $product_id Product id.
	 * @return bool
	 */
	public static function is_sundry( $product_id ) {
		return '1' === get_post_meta( $product_id, self::META_SUNDRY, true );
	}

	/**
	 * @param int $product_id Product id.
	 * @return string
	 */
	public static function unit( $product_id ) {
		return (string) get_post_meta( $product_id, self::META_UNIT, true );
	}

	/**
	 * Cost price per unit excluding VAT, or null when nobody has entered one.
	 *
	 * @param int $product_id Product id.
	 * @return float|null
	 */
	public static function unit_cost( $product_id ) {
		$raw = get_post_meta( $product_id, self::META_COST, true );
		return ( '' === $raw || false === $raw || ! is_numeric( $raw ) ) ? null : (float) $raw;
	}

	/**
	 * @param float  $qty Quantity.
	 * @param string $unit Unit label.
	 * @return string
	 */
	public static function qty_label( $qty, $unit ) {
		$unit = trim( (string) $unit );
		if ( '' === $unit || 'each' === strtolower( $unit ) ) {
			return self::format_qty( $qty );
		}

		return self::format_qty( $qty ) . ' ' . $unit;
	}

	/**
	 * @param float $qty Quantity.
	 * @return string
	 */
	public static function format_qty( $qty ) {
		$qty = (float) $qty;
		if ( abs( $qty - round( $qty ) ) < 0.001 ) {
			return (string) (int) round( $qty );
		}

		return rtrim( rtrim( number_format( $qty, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * @return array<int, object>
	 */
	public static function locations( $active_only = false ) {
		global $wpdb;

		$table = self::locations_table();
		$sql   = "SELECT * FROM {$table}";
		if ( $active_only ) {
			$sql .= ' WHERE is_active = 1';
		}
		$sql .= ' ORDER BY sort_order ASC, id ASC';

		$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $id Location id.
	 * @return object|null
	 */
	public static function get_location( $id ) {
		global $wpdb;

		$table = self::locations_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		return $row ? $row : null;
	}

	/**
	 * Prefer Pretoria when it is active and sellable, otherwise the first sellable location.
	 *
	 * @return int
	 */
	public static function shop_location_id() {
		$fallback = 0;
		foreach ( self::locations( true ) as $location ) {
			if ( ! $location->is_sellable ) {
				continue;
			}
			if ( ! $fallback ) {
				$fallback = (int) $location->id;
			}
			if ( 'pretoria' === strtolower( $location->name ) ) {
				return (int) $location->id;
			}
		}

		return $fallback;
	}

	/**
	 * @param string $name Location name.
	 * @param string $code Short code.
	 * @param bool   $is_sellable Whether the quantity counts on the shop.
	 * @return array|WP_Error
	 */
	public static function add_location( $name, $code, $is_sellable ) {
		global $wpdb;

		$name = trim( sanitize_text_field( $name ) );
		$code = strtoupper( trim( sanitize_text_field( $code ) ) );
		if ( '' === $name ) {
			return new WP_Error( 'bs_stock_location', 'Enter a location name.', array( 'status' => 400 ) );
		}

		$taken = self::code_taken( $code, 0 );
		if ( is_wp_error( $taken ) ) {
			return $taken;
		}

		$sort = (int) $wpdb->get_var( 'SELECT MAX(sort_order) FROM ' . self::locations_table() );
		$ok   = $wpdb->insert(
			self::locations_table(),
			array(
				'name'        => $name,
				'code'        => $code,
				'is_sellable' => $is_sellable ? 1 : 0,
				'is_active'   => 1,
				'sort_order'  => $sort + 1,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( ! $ok ) {
			return new WP_Error( 'bs_stock_location', 'The location could not be saved.', array( 'status' => 500 ) );
		}

		return self::format_location( self::get_location( (int) $wpdb->insert_id ) );
	}

	/**
	 * @param int   $id Location id.
	 * @param array $fields Fields to change.
	 * @return array|WP_Error
	 */
	public static function update_location( $id, $fields ) {
		global $wpdb;

		$location = self::get_location( $id );
		if ( ! $location ) {
			return new WP_Error( 'bs_stock_location', 'That location does not exist.', array( 'status' => 404 ) );
		}

		$data   = array();
		$format = array();

		if ( array_key_exists( 'name', $fields ) ) {
			$name = trim( sanitize_text_field( $fields['name'] ) );
			if ( '' === $name ) {
				return new WP_Error( 'bs_stock_location', 'Enter a location name.', array( 'status' => 400 ) );
			}
			$data['name'] = $name;
			$format[]     = '%s';
		}

		if ( array_key_exists( 'code', $fields ) ) {
			$code  = strtoupper( trim( sanitize_text_field( $fields['code'] ) ) );
			$taken = self::code_taken( $code, $id );
			if ( is_wp_error( $taken ) ) {
				return $taken;
			}
			$data['code'] = $code;
			$format[]     = '%s';
		}

		if ( array_key_exists( 'is_sellable', $fields ) ) {
			$data['is_sellable'] = $fields['is_sellable'] ? 1 : 0;
			$format[]             = '%d';
		}

		if ( array_key_exists( 'is_active', $fields ) ) {
			$active = $fields['is_active'] ? 1 : 0;
			if ( ! $active && self::active_count_except( $id ) < 1 ) {
				return new WP_Error( 'bs_stock_location', 'Keep at least one location active.', array( 'status' => 400 ) );
			}
			if ( ! $active ) {
				$held = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(ABS(qty)), 0) FROM ' . self::balances_table() . ' WHERE location_id = %d', $id ) );
				if ( $held > 0 ) {
					return new WP_Error( 'bs_stock_location', 'Move the stock out before deactivating this location.', array( 'status' => 400 ) );
				}
			}
			$data['is_active'] = $active;
			$format[]          = '%d';
		}

		if ( $data ) {
			$updated = $wpdb->update( self::locations_table(), $data, array( 'id' => $id ), $format, array( '%d' ) );
			if ( false === $updated ) {
				return new WP_Error( 'bs_stock_location', 'The location could not be saved.', array( 'status' => 500 ) );
			}
		}

		if ( class_exists( 'BS_Stock_Sync' ) && ( array_key_exists( 'is_sellable', $fields ) || array_key_exists( 'is_active', $fields ) ) ) {
			BS_Stock_Sync::push_all_tracked();
		}

		return self::format_location( self::get_location( $id ) );
	}

	/**
	 * @param string $code Location code.
	 * @param int    $except_id Location to ignore.
	 * @return true|WP_Error
	 */
	private static function code_taken( $code, $except_id ) {
		global $wpdb;

		if ( '' === $code ) {
			return true;
		}

		$table = self::locations_table();
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE code = %s AND id != %d LIMIT 1",
				$code,
				$except_id
			)
		);

		if ( $found ) {
			return new WP_Error( 'bs_stock_location', 'That location code is already used.', array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * @param int $except_id Location to ignore.
	 * @return int
	 */
	private static function active_count_except( $except_id ) {
		global $wpdb;

		$table = self::locations_table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE is_active = 1 AND id != %d", $except_id ) );
	}

	/**
	 * @param object|null $location Location row.
	 * @return array
	 */
	public static function format_location( $location ) {
		if ( ! $location ) {
			return array();
		}

		return array(
			'id'          => (int) $location->id,
			'name'        => $location->name,
			'code'        => $location->code,
			'is_sellable' => (bool) $location->is_sellable,
			'is_active'   => (bool) $location->is_active,
			'sort_order'  => (int) $location->sort_order,
		);
	}

	/**
	 * @param int $product_id Product id.
	 * @param int $location_id Location id.
	 * @return float
	 */
	public static function balance( $product_id, $location_id ) {
		global $wpdb;

		$table = self::balances_table();
		$qty   = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT qty FROM {$table} WHERE product_id = %d AND location_id = %d",
				$product_id,
				$location_id
			)
		);

		return null === $qty ? 0.0 : (float) $qty;
	}

	/**
	 * @param int $product_id Product id.
	 * @return array<int, float>
	 */
	public static function balance_map( $product_id ) {
		global $wpdb;

		$table = self::balances_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT location_id, qty FROM {$table} WHERE product_id = %d",
				$product_id
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->location_id ] = (float) $row->qty;
		}

		return $map;
	}

	/**
	 * Quantity that can be published to the shop.
	 *
	 * @param int $product_id Product id.
	 * @return float
	 */
	public static function sellable_qty( $product_id ) {
		$total = 0.0;
		$map   = self::balance_map( $product_id );
		foreach ( self::locations( true ) as $location ) {
			if ( $location->is_sellable && isset( $map[ (int) $location->id ] ) ) {
				$total += $map[ (int) $location->id ];
			}
		}

		return (float) self::normalize_qty( $total, self::is_sundry( $product_id ) );
	}

	/**
	 * @param int $product_id Product id.
	 * @return float
	 */
	public static function threshold( $product_id ) {
		$raw = get_post_meta( $product_id, self::META_LOW, true );
		if ( '' === $raw || false === $raw ) {
			return (float) get_option( 'bikesense_stock_default_low', 1 );
		}

		$qty = self::normalize_qty( $raw );
		return null === $qty ? (float) get_option( 'bikesense_stock_default_low', 1 ) : $qty;
	}

	/**
	 * Goods in, goods out, count, or transfer.
	 *
	 * @param array $args Movement arguments.
	 * @return array|WP_Error
	 */
	public static function record( $args ) {
		$type = isset( $args['type'] ) ? $args['type'] : '';
		if ( 'transfer' === $type ) {
			return self::transfer( $args );
		}
		if ( 'count' === $type ) {
			return self::count_stock( $args );
		}
		if ( 'in' === $type || 'out' === $type ) {
			return self::adjust( $args );
		}

		return new WP_Error( 'bs_stock_type', 'Unknown movement.', array( 'status' => 400 ) );
	}

	/**
	 * @param array $args Movement arguments.
	 * @return array|WP_Error
	 */
	private static function adjust( $args ) {
		$product  = self::require_product( $args );
		$location = self::require_location( isset( $args['location_id'] ) ? $args['location_id'] : 0 );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$qty = self::normalize_qty( isset( $args['qty'] ) ? $args['qty'] : null, self::is_sundry( $product->get_id() ) );
		if ( null === $qty || $qty <= 0 ) {
			return new WP_Error( 'bs_stock_qty', 'Enter a quantity greater than zero.', array( 'status' => 400 ) );
		}

		$delta = 'out' === $args['type'] ? 0 - $qty : $qty;
		$moved = self::write_movement(
			$product,
			$location,
			$args['type'],
			null,
			$delta,
			isset( $args['note'] ) ? $args['note'] : '',
			isset( $args['allow_negative'] ) ? (bool) $args['allow_negative'] : false,
			$args
		);
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		self::after_movement( $product->get_id() );
		return self::movement_result( $product->get_id(), array( $moved ) );
	}

	/**
	 * @param array $args Movement arguments.
	 * @return array|WP_Error
	 */
	private static function count_stock( $args ) {
		$product  = self::require_product( $args );
		$location = self::require_location( isset( $args['location_id'] ) ? $args['location_id'] : 0 );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$qty = self::normalize_qty( isset( $args['qty'] ) ? $args['qty'] : null, self::is_sundry( $product->get_id() ) );
		if ( null === $qty || $qty < 0 ) {
			return new WP_Error( 'bs_stock_qty', 'Enter the quantity you counted.', array( 'status' => 400 ) );
		}

		$moved = self::write_movement(
			$product,
			$location,
			'count',
			$qty,
			null,
			isset( $args['note'] ) ? $args['note'] : '',
			false,
			$args
		);
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		self::after_movement( $product->get_id() );
		return self::movement_result( $product->get_id(), array( $moved ) );
	}

	/**
	 * @param array $args Movement arguments.
	 * @return array|WP_Error
	 */
	private static function transfer( $args ) {
		$product = self::require_product( $args );
		$from    = self::require_location( isset( $args['location_id'] ) ? $args['location_id'] : 0 );
		$to      = self::require_location( isset( $args['to_location_id'] ) ? $args['to_location_id'] : 0 );
		if ( is_wp_error( $product ) ) {
			return $product;
		}
		if ( is_wp_error( $from ) ) {
			return $from;
		}
		if ( is_wp_error( $to ) ) {
			return $to;
		}
		if ( (int) $from->id === (int) $to->id ) {
			return new WP_Error( 'bs_stock_transfer', 'Choose a different location to transfer to.', array( 'status' => 400 ) );
		}

		$qty = self::normalize_qty( isset( $args['qty'] ) ? $args['qty'] : null, self::is_sundry( $product->get_id() ) );
		if ( null === $qty || $qty <= 0 ) {
			return new WP_Error( 'bs_stock_qty', 'Enter a quantity greater than zero.', array( 'status' => 400 ) );
		}

		$note = trim( sanitize_textarea_field( isset( $args['note'] ) ? $args['note'] : '' ) );
		$out  = trim( $note . ( '' === $note ? '' : ' ' ) . 'Transfer to ' . $to->name );
		$in   = trim( $note . ( '' === $note ? '' : ' ' ) . 'Transfer from ' . $from->name );
		$key  = 'transfer:' . wp_generate_password( 12, false, false );

		self::begin();
		$left = self::write_movement( $product, $from, 'transfer_out', null, 0 - $qty, $out, false, array( 'source_key' => $key . ':out' ), false );
		if ( is_wp_error( $left ) ) {
			self::rollback();
			return $left;
		}
		$right = self::write_movement( $product, $to, 'transfer_in', null, $qty, $in, false, array( 'source_key' => $key . ':in' ), false );
		if ( is_wp_error( $right ) ) {
			self::rollback();
			return $right;
		}
		self::commit();

		self::after_movement( $product->get_id() );
		return self::movement_result( $product->get_id(), array( $left, $right ) );
	}

	/**
	 * Online orders and cancellations. A repeated source key does not move stock twice.
	 *
	 * @param array $args Movement arguments.
	 * @return array|WP_Error
	 */
	public static function record_order( $args ) {
		$product_id = isset( $args['product_id'] ) ? (int) $args['product_id'] : 0;
		$product    = self::require_product( array( 'product_id' => $product_id ) );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$location_id = isset( $args['location_id'] ) ? (int) $args['location_id'] : self::shop_location_id();
		$location    = self::require_location( $location_id );
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$key = isset( $args['source_key'] ) ? sanitize_text_field( $args['source_key'] ) : '';
		if ( '' !== $key ) {
			$existing = self::movement_by_source( $key );
			if ( $existing ) {
				return self::movement_result( $product_id, array( self::format_movement( $existing ) ) );
			}
		}

		$delta = self::normalize_qty( isset( $args['delta'] ) ? $args['delta'] : null );
		if ( null === $delta || 0.0 === (float) $delta ) {
			return new WP_Error( 'bs_stock_qty', 'Order quantity was empty.', array( 'status' => 400 ) );
		}

		$type  = $delta < 0 ? 'out' : 'in';
		$moved = self::write_movement(
			$product,
			$location,
			$type,
			null,
			$delta,
			isset( $args['note'] ) ? $args['note'] : '',
			true,
			array(
				'source_key'    => $key,
				'user_id'       => 0,
				'order_id'      => isset( $args['order_id'] ) ? (int) $args['order_id'] : 0,
				'order_item_id' => isset( $args['order_item_id'] ) ? (int) $args['order_item_id'] : 0,
			)
		);
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		self::after_movement( $product_id );
		return self::movement_result( $product_id, array( $moved ) );
	}

	/**
	 * One job booking ('out') or return ('in'). The caller holds the transaction and calls after_movement after it commits.
	 *
	 * @param array $args product (WC_Product), location_id, type, qty (already normalized), note, job_id, source_key.
	 * @return array|WP_Error Formatted movement.
	 */
	public static function record_job( $args ) {
		$type     = isset( $args['type'] ) ? $args['type'] : '';
		$product  = isset( $args['product'] ) ? $args['product'] : null;
		$location = self::require_location( isset( $args['location_id'] ) ? $args['location_id'] : 0 );
		if ( ! is_object( $product ) || ! in_array( $type, array( 'in', 'out' ), true ) ) {
			return new WP_Error( 'bs_stock_type', 'Unknown movement.', array( 'status' => 400 ) );
		}
		if ( is_wp_error( $location ) ) {
			return $location;
		}

		$qty = isset( $args['qty'] ) ? (float) $args['qty'] : 0.0;
		if ( $qty <= 0 ) {
			return new WP_Error( 'bs_stock_qty', 'Enter a quantity greater than zero.', array( 'status' => 400 ) );
		}

		return self::write_movement(
			$product,
			$location,
			$type,
			null,
			'out' === $type ? 0 - $qty : $qty,
			isset( $args['note'] ) ? $args['note'] : '',
			'in' === $type,
			array(
				'source_key' => isset( $args['source_key'] ) ? $args['source_key'] : '',
				'job_id'     => isset( $args['job_id'] ) ? (int) $args['job_id'] : 0,
			),
			false
		);
	}

	/**
	 * A single countable part, or the same friendly errors as stock taking.
	 *
	 * @param int $product_id Product id.
	 * @return WC_Product|WP_Error
	 */
	public static function countable_product( $product_id ) {
		return self::require_product( array( 'product_id' => (int) $product_id ) );
	}

	/**
	 * @param string $source_key Idempotency key.
	 * @return bool
	 */
	public static function source_used( $source_key ) {
		return '' !== $source_key && null !== self::movement_by_source( $source_key );
	}

	/**
	 * @param WC_Product $product Product.
	 * @param object     $location Location row.
	 * @param string     $type Movement type.
	 * @param float|null $absolute Counted quantity, or null to apply a delta.
	 * @param float|null $delta Signed change, or null when counting.
	 * @param string     $note Note.
	 * @param bool       $allow_negative Allow the balance to drop below zero.
	 * @param array      $context Extra columns.
	 * @param bool       $own_transaction Wrap this write in a transaction.
	 * @return array|WP_Error
	 */
	private static function write_movement( $product, $location, $type, $absolute, $delta, $note, $allow_negative, $context, $own_transaction = true ) {
		global $wpdb;

		$product_id  = $product->get_id();
		$location_id = (int) $location->id;
		$note        = trim( sanitize_textarea_field( $note ) );
		$note        = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 500 ) : substr( $note, 0, 500 );
		$source_key  = isset( $context['source_key'] ) ? sanitize_text_field( $context['source_key'] ) : '';
		$source_key  = '' === $source_key ? null : $source_key;

		if ( $own_transaction ) {
			self::begin();
		}

		$current = self::balance( $product_id, $location_id );
		if ( null !== $absolute ) {
			$balance = (float) $absolute;
			$delta   = $balance - $current;
			$stored  = $balance;
		} else {
			$delta   = (float) $delta;
			$balance = $current + $delta;
			$stored  = abs( $delta );
		}

		if ( ! $allow_negative && $balance < -0.0001 ) {
			if ( $own_transaction ) {
				self::rollback();
			}
			return new WP_Error(
				'bs_stock_short',
				sprintf( 'Only %s on hand at %s.', self::format_qty( $current ), $location->name ),
				array( 'status' => 400 )
			);
		}

		$now = current_time( 'mysql' );
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . self::balances_table() . ' (product_id, location_id, qty, updated_at) VALUES (%d, %d, %f, %s)
				ON DUPLICATE KEY UPDATE qty = %f, updated_at = %s',
				$product_id,
				$location_id,
				$balance,
				$now,
				$balance,
				$now
			)
		);

		if ( $wpdb->last_error ) {
			if ( $own_transaction ) {
				self::rollback();
			}
			return new WP_Error( 'bs_stock_save', 'The balance could not be saved.', array( 'status' => 500 ) );
		}

		$inserted = $wpdb->insert(
			self::movements_table(),
			array(
				'product_id'    => $product_id,
				'location_id'   => $location_id,
				'type'          => $type,
				'qty'           => $stored,
				'qty_delta'     => $delta,
				'balance_after' => $balance,
				'note'          => $note,
				'user_id'       => isset( $context['user_id'] ) ? (int) $context['user_id'] : get_current_user_id(),
				'order_id'      => isset( $context['order_id'] ) ? (int) $context['order_id'] : 0,
				'order_item_id' => isset( $context['order_item_id'] ) ? (int) $context['order_item_id'] : 0,
				'job_id'        => empty( $context['job_id'] ) ? null : (int) $context['job_id'],
				'source_key'    => $source_key,
				'created_at'    => $now,
			),
			array( '%d', '%d', '%s', '%f', '%f', '%f', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			if ( $own_transaction ) {
				self::rollback();
			}
			return new WP_Error( 'bs_stock_save', 'The movement could not be saved.', array( 'status' => 500 ) );
		}

		$movement_id = (int) $wpdb->insert_id;
		if ( $own_transaction ) {
			self::commit();
		}

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::movements_table() . ' WHERE id = %d', $movement_id ) );
		return self::format_movement( $row );
	}

	/**
	 * @param int $product_id Product id.
	 */
	public static function after_movement( $product_id ) {
		update_post_meta( $product_id, self::META_TRACKED, '1' );
		if ( class_exists( 'BS_Stock_Sync' ) ) {
			BS_Stock_Sync::push( $product_id );
		}
	}

	/**
	 * @param int   $product_id Product id.
	 * @param array $movements Formatted movements.
	 * @return array
	 */
	private static function movement_result( $product_id, $movements ) {
		return array(
			'movements' => $movements,
			'product'   => self::product_payload( $product_id ),
		);
	}

	/**
	 * @param array $args Request arguments.
	 * @return WC_Product|WP_Error
	 */
	private static function require_product( $args ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'bs_stock_woocommerce', 'WooCommerce is not available.', array( 'status' => 500 ) );
		}

		$product_id = isset( $args['product_id'] ) ? (int) $args['product_id'] : 0;
		if ( ! $product_id && ! empty( $args['sku'] ) && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = (int) wc_get_product_id_by_sku( $args['sku'] );
		}

		$product = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product ) {
			return new WP_Error( 'bs_stock_product', 'That part was not found.', array( 'status' => 404 ) );
		}
		if ( $product->is_type( array( 'variable', 'grouped', 'external' ) ) ) {
			return new WP_Error( 'bs_stock_product', 'Count the specific part number, not the group.', array( 'status' => 400 ) );
		}

		return $product;
	}

	/**
	 * @param int $location_id Location id.
	 * @return object|WP_Error
	 */
	private static function require_location( $location_id ) {
		$location = self::get_location( (int) $location_id );
		if ( ! $location || ! $location->is_active ) {
			return new WP_Error( 'bs_stock_location', 'Choose an active location.', array( 'status' => 400 ) );
		}

		return $location;
	}

	/**
	 * @param string $source_key Idempotency key.
	 * @return object|null
	 */
	private static function movement_by_source( $source_key ) {
		global $wpdb;

		$table = self::movements_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_key = %s LIMIT 1", $source_key ) );
		return $row ? $row : null;
	}

	/**
	 * @param string $query Part number, barcode, or name.
	 * @param int    $limit Maximum results.
	 * @return array|WP_Error
	 */
	public static function search( $query, $limit = 20 ) {
		global $wpdb;

		$query = trim( wp_strip_all_tags( (string) $query ) );
		if ( '' === $query ) {
			return new WP_Error( 'bs_stock_query', 'Enter a part number, barcode, or name.', array( 'status' => 400 ) );
		}

		if ( ! $limit ) {
			$limit = 20;
		}
		$limit = max( 1, min( 20, (int) $limit ) );
		$ids   = array();

		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$exact = (int) wc_get_product_id_by_sku( $query );
			if ( $exact ) {
				$ids[] = $exact;
			}
		}

		$like = '%' . $wpdb->esc_like( $query ) . '%';
		$ids  = array_merge(
			$ids,
			self::meta_ids( self::META_BARCODE, $query, true, $limit ),
			self::meta_ids( '_sku', $like, false, $limit ),
			array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private') AND post_title LIKE %s LIMIT %d", $like, $limit ) ) )
		);

		$unique  = array();
		$results = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( ! $id || isset( $unique[ $id ] ) ) {
				continue;
			}
			$unique[ $id ] = true;
			$payload       = self::product_payload( $id );
			if ( is_wp_error( $payload ) || empty( $payload['published'] ) ) {
				continue;
			}
			$results[] = $payload;
			if ( count( $results ) >= $limit ) {
				break;
			}
		}

		return array(
			'query'   => $query,
			'results' => $results,
		);
	}

	/**
	 * @param string $meta_key Meta key.
	 * @param string $value Exact value or LIKE pattern.
	 * @param bool   $exact Exact match.
	 * @param int    $limit Limit.
	 * @return int[]
	 */
	private static function meta_ids( $meta_key, $value, $exact, $limit ) {
		global $wpdb;

		$compare = $exact ? '=' : 'LIKE';
		$sql     = $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value {$compare} %s AND p.post_type IN ('product','product_variation') AND p.post_status IN ('publish','private') LIMIT %d",
			$meta_key,
			$value,
			$limit
		);

		return array_map( 'intval', (array) $wpdb->get_col( $sql ) );
	}

	/**
	 * @param int    $product_id Product id.
	 * @param string $sku SKU.
	 * @return array|WP_Error
	 */
	public static function get_product( $product_id = 0, $sku = '' ) {
		if ( ! $product_id && '' !== $sku && function_exists( 'wc_get_product_id_by_sku' ) ) {
			$product_id = (int) wc_get_product_id_by_sku( $sku );
			if ( ! $product_id ) {
				$found = self::meta_ids( self::META_BARCODE, $sku, true, 1 );
				$product_id = $found ? (int) $found[0] : 0;
			}
		}

		$payload = self::product_payload( $product_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( empty( $payload['published'] ) ) {
			return new WP_Error( 'bs_stock_product', 'That part was not found.', array( 'status' => 404 ) );
		}

		return $payload;
	}

	/**
	 * @param int    $product_id Product id.
	 * @param string $barcode Barcode. Empty clears it.
	 * @param mixed  $low_stock Threshold. Null leaves it, empty string clears it.
	 * @param array  $extra Optional sundry (bool), unit (string), cost (empty string clears).
	 * @return array|WP_Error
	 */
	public static function update_product_meta( $product_id, $barcode, $low_stock, $extra = array() ) {
		$payload = self::get_product( $product_id );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$unit = null;
		if ( array_key_exists( 'unit', $extra ) ) {
			$unit = trim( sanitize_text_field( (string) $extra['unit'] ) );
			if ( strlen( $unit ) > 16 ) {
				return new WP_Error( 'bs_stock_unit', 'Keep the unit short, like L, ml, each, or m.', array( 'status' => 400 ) );
			}
		}

		$cost = null;
		if ( array_key_exists( 'cost', $extra ) && null !== $extra['cost'] && '' !== $extra['cost'] ) {
			if ( ! is_numeric( $extra['cost'] ) || (float) $extra['cost'] < 0 || (float) $extra['cost'] > 10000000 ) {
				return new WP_Error( 'bs_stock_cost', 'Enter a cost price in rand, or leave it blank.', array( 'status' => 400 ) );
			}
			$cost = number_format( round( (float) $extra['cost'], 2 ), 2, '.', '' );
		}

		if ( null !== $barcode ) {
			$barcode = trim( sanitize_text_field( $barcode ) );
			if ( strlen( $barcode ) > 64 ) {
				return new WP_Error( 'bs_stock_barcode', 'That barcode is too long.', array( 'status' => 400 ) );
			}
			if ( '' === $barcode ) {
				delete_post_meta( $product_id, self::META_BARCODE );
			} else {
				$others = self::meta_ids( self::META_BARCODE, $barcode, true, 5 );
				foreach ( $others as $other ) {
					if ( (int) $other !== (int) $product_id ) {
						return new WP_Error( 'bs_stock_barcode', 'That barcode is already on another part.', array( 'status' => 400 ) );
					}
				}
				update_post_meta( $product_id, self::META_BARCODE, $barcode );
			}
		}

		if ( null !== $low_stock ) {
			if ( '' === $low_stock || null === $low_stock ) {
				delete_post_meta( $product_id, self::META_LOW );
			} else {
				$qty = self::normalize_qty( $low_stock );
				if ( null === $qty || $qty < 0 ) {
					return new WP_Error( 'bs_stock_low', 'Enter a low-stock number, or leave it blank.', array( 'status' => 400 ) );
				}
				update_post_meta( $product_id, self::META_LOW, self::format_qty( $qty ) );
			}
		}

		if ( array_key_exists( 'sundry', $extra ) ) {
			if ( $extra['sundry'] ) {
				update_post_meta( $product_id, self::META_SUNDRY, '1' );
			} else {
				delete_post_meta( $product_id, self::META_SUNDRY );
			}
		}

		if ( null !== $unit ) {
			if ( '' === $unit ) {
				delete_post_meta( $product_id, self::META_UNIT );
			} else {
				update_post_meta( $product_id, self::META_UNIT, $unit );
			}
		}

		if ( array_key_exists( 'cost', $extra ) ) {
			if ( null === $cost ) {
				delete_post_meta( $product_id, self::META_COST );
			} else {
				update_post_meta( $product_id, self::META_COST, $cost );
			}
		}

		return self::product_payload( $product_id );
	}

	/**
	 * @return array
	 */
	public static function low_stock() {
		global $wpdb;

		$table = self::balances_table();
		$ids   = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$items = array();

		foreach ( (array) $ids as $product_id ) {
			$product_id = (int) $product_id;
			if ( '1' !== get_post_meta( $product_id, self::META_TRACKED, true ) ) {
				continue;
			}
			$payload = self::product_payload( $product_id );
			if ( is_wp_error( $payload ) || empty( $payload['is_low'] ) ) {
				continue;
			}
			$items[] = $payload;
		}

		usort(
			$items,
			function ( $a, $b ) {
				if ( $a['sellable_qty'] === $b['sellable_qty'] ) {
					return strcmp( $a['name'], $b['name'] );
				}
				return $a['sellable_qty'] < $b['sellable_qty'] ? -1 : 1;
			}
		);

		return array( 'items' => $items );
	}

	/**
	 * @param int $product_id Optional product filter.
	 * @param int $limit Maximum rows.
	 * @param int $job_id Optional job filter.
	 * @return array
	 */
	public static function movements( $product_id = 0, $limit = 50, $job_id = 0 ) {
		global $wpdb;

		$limit = max( 1, min( 100, (int) $limit ) );
		$table = self::movements_table();
		if ( $job_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d ORDER BY id DESC LIMIT %d", $job_id, $limit ) );
		} elseif ( $product_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE product_id = %d ORDER BY id DESC LIMIT %d", $product_id, $limit ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
		}

		$movements = array();
		foreach ( (array) $rows as $row ) {
			$movements[] = self::format_movement( $row );
		}

		return array( 'movements' => $movements );
	}

	/**
	 * @param int $product_id Product or variation id.
	 * @return array|WP_Error
	 */
	public static function product_payload( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new WP_Error( 'bs_stock_woocommerce', 'WooCommerce is not available.', array( 'status' => 500 ) );
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'bs_stock_product', 'That part was not found.', array( 'status' => 404 ) );
		}

		$status    = $product->get_status();
		$published = 'publish' === $status || ( 'private' === $status );
		$parent_id = $product->get_parent_id();
		if ( $published && $parent_id ) {
			$parent = wc_get_product( $parent_id );
			$published = $parent && 'publish' === $parent->get_status();
		}

		$image_id = $product->get_image_id();
		$image    = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		if ( ! $image && function_exists( 'wc_placeholder_img_src' ) ) {
			$image = wc_placeholder_img_src( 'thumbnail' );
		}

		$balances = self::balance_map( $product_id );
		$places   = array();
		foreach ( self::locations( true ) as $location ) {
			$location_id = (int) $location->id;
			$places[]    = array(
				'id'          => $location_id,
				'name'        => $location->name,
				'code'        => $location->code,
				'is_sellable' => (bool) $location->is_sellable,
				'qty'         => isset( $balances[ $location_id ] ) ? (float) $balances[ $location_id ] : 0,
			);
		}

		$sellable = self::sellable_qty( $product_id );
		$tracked  = '1' === get_post_meta( $product_id, self::META_TRACKED, true );
		$override = get_post_meta( $product_id, self::META_LOW, true );
		$threshold = self::threshold( $product_id );

		return array(
			'id'                  => $product->get_id(),
			'name'                => $product->get_name(),
			'sku'                 => $product->get_sku(),
			'barcode'             => (string) get_post_meta( $product_id, self::META_BARCODE, true ),
			'permalink'           => $product->get_permalink(),
			'image'               => $image ? $image : '',
			'price'               => $product->get_price(),
			'currency'            => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '',
			'published'           => (bool) $published,
			'countable'           => ! $product->is_type( array( 'variable', 'grouped', 'external' ) ),
			'tracked'             => $tracked,
			'low_stock_override'  => ( '' === $override || false === $override ) ? null : (float) $override,
			'low_stock_threshold' => $threshold,
			'is_low'              => $tracked && $sellable <= $threshold,
			'sellable_qty'        => $sellable,
			'sundry'              => self::is_sundry( $product_id ),
			'unit'                => self::unit( $product_id ),
			'cost'                => self::unit_cost( $product_id ),
			'locations'           => $places,
			'shop'                => array(
				'manage_stock'   => (bool) $product->get_manage_stock(),
				'stock_quantity' => $product->get_stock_quantity(),
				'stock_status'   => $product->get_stock_status(),
			),
		);
	}

	/**
	 * @param object|null $row Movement row.
	 * @return array
	 */
	public static function format_movement( $row ) {
		if ( ! $row ) {
			return array();
		}

		$product  = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $row->product_id ) : false;
		$location = self::get_location( (int) $row->location_id );
		$user     = $row->user_id ? get_userdata( (int) $row->user_id ) : false;
		$when     = mysql2date( 'j M Y, H:i', $row->created_at );
		$job_id   = isset( $row->job_id ) ? (int) $row->job_id : 0;

		return array(
			'id'             => (int) $row->id,
			'product_id'     => (int) $row->product_id,
			'product_name'   => $product ? $product->get_name() : 'Removed part',
			'sku'            => $product ? $product->get_sku() : '',
			'location_id'    => (int) $row->location_id,
			'location_name'  => $location ? $location->name : 'Removed location',
			'type'           => $row->type,
			'type_label'     => self::type_label( $row->type ),
			'qty'            => (float) $row->qty,
			'qty_delta'      => (float) $row->qty_delta,
			'balance_after'  => (float) $row->balance_after,
			'note'           => $row->note,
			'user_id'        => (int) $row->user_id,
			'user_name'      => $user ? $user->display_name : ( $row->order_id ? 'Website order' : 'Stock' ),
			'order_id'       => (int) $row->order_id,
			'order_item_id'  => (int) $row->order_item_id,
			'job_id'         => $job_id,
			'job_number'     => $job_id && class_exists( 'BS_Stock_Jobs' ) ? BS_Stock_Jobs::number_for( $job_id ) : '',
			'created_at'     => $row->created_at,
			'created_label'  => $when ? $when : $row->created_at,
		);
	}

	/**
	 * @param string $type Movement type.
	 * @return string
	 */
	public static function type_label( $type ) {
		$labels = array(
			'in'           => 'Goods in',
			'out'          => 'Goods out',
			'count'        => 'Count',
			'transfer_in'  => 'Transfer in',
			'transfer_out' => 'Transfer out',
		);

		return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
	}

	/**
	 * Tracked product ids that have a balance row.
	 *
	 * @return int[]
	 */
	public static function tracked_product_ids() {
		global $wpdb;

		$table = self::balances_table();
		$ids   = $wpdb->get_col( "SELECT DISTINCT product_id FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out   = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( $id && '1' === get_post_meta( $id, self::META_TRACKED, true ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	public static function begin() {
		global $wpdb;
		$wpdb->query( 'START TRANSACTION' );
	}

	public static function commit() {
		global $wpdb;
		$wpdb->query( 'COMMIT' );
	}

	public static function rollback() {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
	}
}
