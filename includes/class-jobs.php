<?php
/**
 * Workshop job cards: parts and sundries booked out of stock, labour, costing, and the shop order.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_Jobs {

	const META_COST_RATE   = '_bs_labour_cost_rate';
	const META_CHARGE_RATE = '_bs_labour_charge_rate';
	const ORDER_META_JOB   = '_bs_stock_job_id';
	const ORDER_META_NUM   = '_bs_stock_job_number';

	/**
	 * @var array<int, string>
	 */
	private static $numbers = array();

	/** @var array<int, bool> Jobs locked through the option fallback instead of GET_LOCK. */
	private static $option_locks = array();

	/**
	 * @return string
	 */
	public static function jobs_table() {
		global $wpdb;
		return $wpdb->prefix . 'bs_stock_jobs';
	}

	/**
	 * @return string
	 */
	public static function lines_table() {
		global $wpdb;
		return $wpdb->prefix . 'bs_stock_job_lines';
	}

	/**
	 * @return string
	 */
	public static function labour_table() {
		global $wpdb;
		return $wpdb->prefix . 'bs_stock_job_labour';
	}

	/**
	 * @return array<string, string>
	 */
	public static function statuses() {
		return array(
			'open'          => 'Open',
			'in_progress'   => 'In progress',
			'waiting_parts' => 'Waiting for parts',
			'completed'     => 'Completed',
			'invoiced'      => 'Invoiced',
			'cancelled'     => 'Cancelled',
		);
	}

	/**
	 * Stock and labour can only change while the job is being worked on.
	 *
	 * @param string $status Status.
	 * @return bool
	 */
	public static function is_working( $status ) {
		return in_array( $status, array( 'open', 'in_progress', 'waiting_parts' ), true );
	}

	/**
	 * @param object $job Job row.
	 * @return true|WP_Error
	 */
	private static function require_working( $job ) {
		if ( self::is_working( $job->status ) ) {
			return true;
		}
		if ( 'completed' === $job->status ) {
			return new WP_Error( 'bs_stock_job_closed', 'This job is completed. Reopen it to change parts or labour.', array( 'status' => 400 ) );
		}

		return new WP_Error( 'bs_stock_job_closed', 'This job is ' . strtolower( self::status_label( $job->status ) ) . ' and cannot change.', array( 'status' => 400 ) );
	}

	/**
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = self::statuses();
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * @param int $id Job id.
	 * @return object|null
	 */
	public static function row( $id ) {
		global $wpdb;

		$table = self::jobs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) );
		return $row ? $row : null;
	}

	/**
	 * @param string $number Job number such as JC-0007, or just 7.
	 * @return object|null
	 */
	public static function row_by_number( $number ) {
		global $wpdb;

		$number = strtoupper( trim( sanitize_text_field( (string) $number ) ) );
		if ( '' === $number ) {
			return null;
		}
		if ( ctype_digit( $number ) ) {
			$number = sprintf( 'JC-%04d', (int) $number );
		}

		$table = self::jobs_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_number = %s", $number ) );
		return $row ? $row : null;
	}

	/**
	 * @param int $id Job id.
	 * @return string
	 */
	public static function number_for( $id ) {
		global $wpdb;

		$id = (int) $id;
		if ( ! isset( self::$numbers[ $id ] ) ) {
			$table               = self::jobs_table();
			self::$numbers[ $id ] = (string) $wpdb->get_var( $wpdb->prepare( "SELECT job_number FROM {$table} WHERE id = %d", $id ) );
		}

		return self::$numbers[ $id ];
	}

	/**
	 * @param array $fields Header fields.
	 * @return array|WP_Error
	 */
	public static function create( $fields ) {
		global $wpdb;

		$data = self::clean_header( is_array( $fields ) ? $fields : array(), true );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now                = current_time( 'mysql' );
		$data['job_number'] = 'NEW-' . wp_generate_password( 12, false, false );
		$data['status']     = 'open';
		$data['created_by'] = get_current_user_id();
		$data['created_at'] = $now;
		$data['updated_at'] = $now;

		$ok = $wpdb->insert( self::jobs_table(), $data, self::formats( $data ) );
		if ( ! $ok ) {
			return new WP_Error( 'bs_stock_job_save', 'The job could not be saved.', array( 'status' => 500 ) );
		}

		$id     = (int) $wpdb->insert_id;
		$number = sprintf( 'JC-%04d', $id );
		$named  = $wpdb->update( self::jobs_table(), array( 'job_number' => $number ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		if ( false === $named ) {
			$wpdb->delete( self::jobs_table(), array( 'id' => $id ), array( '%d' ) );
			return new WP_Error( 'bs_stock_job_save', 'The job could not be saved.', array( 'status' => 500 ) );
		}

		return self::get( $id );
	}

	/**
	 * Header edits and status changes. Invoicing has its own action.
	 *
	 * @param int   $id Job id.
	 * @param array $fields Header fields, status, and return_stock for cancelling.
	 * @return array|WP_Error
	 */
	public static function update( $id, $fields ) {
		$fields = is_array( $fields ) ? $fields : array();
		$job    = self::row( $id );
		if ( ! $job ) {
			return self::not_found();
		}
		if ( ! self::lock( $job->id ) ) {
			return self::busy();
		}

		$result = self::update_locked( (int) $job->id, $fields );
		self::unlock( $job->id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return self::get( $job->id );
	}

	/**
	 * @param int   $id Job id.
	 * @param array $fields Fields.
	 * @return true|WP_Error
	 */
	private static function update_locked( $id, $fields ) {
		global $wpdb;

		$job = self::row( $id );
		if ( in_array( $job->status, array( 'invoiced', 'cancelled' ), true ) ) {
			return new WP_Error( 'bs_stock_job_closed', 'This job is ' . strtolower( self::status_label( $job->status ) ) . ' and cannot change.', array( 'status' => 400 ) );
		}

		$data = self::clean_header( $fields, false );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$now    = current_time( 'mysql' );
		$status = array_key_exists( 'status', $fields ) ? sanitize_key( (string) $fields['status'] ) : $job->status;
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return new WP_Error( 'bs_stock_job_status', 'Choose a valid job status.', array( 'status' => 400 ) );
		}
		if ( 'invoiced' === $status && 'invoiced' !== $job->status ) {
			return new WP_Error( 'bs_stock_job_status', 'Use Create WooCommerce order to invoice a job.', array( 'status' => 400 ) );
		}

		if ( 'cancelled' === $status ) {
			return self::cancel_locked( $job, $data, ! empty( $fields['return_stock'] ) && self::flag( $fields['return_stock'] ) );
		}

		if ( $status !== $job->status ) {
			$data['status'] = $status;
			if ( 'completed' === $status ) {
				$data['completed_at'] = $now;
				$data['completed_by'] = get_current_user_id();
			} elseif ( 'completed' === $job->status ) {
				$data['completed_at'] = null;
				$data['completed_by'] = 0;
			}
		}

		$data['updated_at'] = $now;
		$saved              = $wpdb->update( self::jobs_table(), $data, array( 'id' => $id ), self::formats( $data ), array( '%d' ) );
		if ( false === $saved ) {
			return new WP_Error( 'bs_stock_job_save', 'The job could not be saved.', array( 'status' => 500 ) );
		}

		return true;
	}

	/**
	 * Cancel a job. Stock still on the job goes back to its shelf in the same transaction, or the cancel is refused.
	 *
	 * @param object $job Job row.
	 * @param array  $data Header changes.
	 * @param bool   $return_stock Return everything first.
	 * @return true|WP_Error
	 */
	private static function cancel_locked( $job, $data, $return_stock ) {
		global $wpdb;

		$lines_table = self::lines_table();
		$held        = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(qty), 0) FROM {$lines_table} WHERE job_id = %d", $job->id ) );
		if ( $held > 0.0001 && ! $return_stock ) {
			return new WP_Error( 'bs_stock_job_stock', 'Stock is still booked to this job. Return it first, or cancel with return all stock.', array( 'status' => 409 ) );
		}

		$now                  = current_time( 'mysql' );
		$data['status']       = 'cancelled';
		$data['cancelled_at'] = $now;
		$data['updated_at']   = $now;
		$touched              = array();
		$stamp                = wp_generate_password( 8, false, false );

		BS_Stock_Ledger::begin();
		$lines = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$lines_table} WHERE job_id = %d FOR UPDATE", $job->id ) );
		foreach ( (array) $lines as $line ) {
			$moved = self::return_qty( $job, $line, (float) $line->qty, 'job:' . $job->id . ':cancel:' . $line->id . ':' . $stamp );
			if ( is_wp_error( $moved ) ) {
				BS_Stock_Ledger::rollback();
				return $moved;
			}
			if ( $moved ) {
				$touched[] = (int) $moved;
			}
		}

		$saved = $wpdb->update( self::jobs_table(), $data, array( 'id' => $job->id ), self::formats( $data ), array( '%d' ) );
		if ( false === $saved ) {
			BS_Stock_Ledger::rollback();
			return new WP_Error( 'bs_stock_job_save', 'The job could not be cancelled.', array( 'status' => 500 ) );
		}
		BS_Stock_Ledger::commit();

		foreach ( array_unique( $touched ) as $product_id ) {
			BS_Stock_Ledger::after_movement( $product_id );
		}

		return true;
	}

	/**
	 * Take a part or sundry off the shelf and onto the job.
	 *
	 * @param int    $job_id Job id.
	 * @param int    $product_id Product id.
	 * @param mixed  $qty Quantity.
	 * @param int    $location_id Location id. Zero uses the shop location.
	 * @param string $request_key Client key so a double tap books once.
	 * @return array|WP_Error
	 */
	public static function book( $job_id, $product_id, $qty, $location_id, $request_key ) {
		global $wpdb;

		$key = self::request_key( $request_key );
		$job = self::row( $job_id );
		if ( ! $job ) {
			return self::not_found();
		}
		if ( ! self::lock( $job->id ) ) {
			return self::busy();
		}
		if ( BS_Stock_Ledger::source_used( $key ) ) {
			self::unlock( $job->id );
			return self::get( $job->id );
		}

		$job     = self::row( $job->id );
		$working = self::require_working( $job );
		$product = BS_Stock_Ledger::countable_product( $product_id );
		if ( is_wp_error( $working ) || is_wp_error( $product ) ) {
			self::unlock( $job->id );
			return is_wp_error( $working ) ? $working : $product;
		}

		$product_id = $product->get_id();
		$sundry     = BS_Stock_Ledger::is_sundry( $product_id );
		if ( ! $sundry && is_numeric( $qty ) && abs( (float) $qty - round( (float) $qty ) ) > 0.0001 ) {
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_qty', 'Parts are booked in whole units. Tick Workshop sundry on the part card to use part quantities.', array( 'status' => 400 ) );
		}
		$qty = BS_Stock_Ledger::normalize_qty( $qty, $sundry );
		if ( null === $qty || $qty <= 0 ) {
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_qty', 'Enter a quantity greater than zero.', array( 'status' => 400 ) );
		}

		$location_id = (int) $location_id ? (int) $location_id : BS_Stock_Ledger::shop_location_id();
		$cost        = BS_Stock_Ledger::unit_cost( $product_id );
		$price       = $product->get_price();
		$price       = is_numeric( $price ) ? round( (float) $price, 2 ) : 0.0;
		$now         = current_time( 'mysql' );
		$lines_table = self::lines_table();

		BS_Stock_Ledger::begin();
		$moved = BS_Stock_Ledger::record_job(
			array(
				'type'        => 'out',
				'product'     => $product,
				'location_id' => $location_id,
				'qty'         => $qty,
				'note'        => 'Job ' . $job->job_number,
				'job_id'      => $job->id,
				'source_key'  => $key,
			)
		);
		if ( is_wp_error( $moved ) ) {
			BS_Stock_Ledger::rollback();
			self::unlock( $job->id );
			return $moved;
		}

		$line = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lines_table} WHERE job_id = %d AND product_id = %d AND location_id = %d FOR UPDATE", $job->id, $product_id, $location_id ) );
		if ( $line ) {
			$data = array(
				'qty'        => round( (float) $line->qty + $qty, 2 ),
				'updated_at' => $now,
			);
			if ( null === $line->unit_cost && null !== $cost ) {
				$data['unit_cost'] = $cost;
			}
			$ok = false !== $wpdb->update( $lines_table, $data, array( 'id' => $line->id ), self::formats( $data ), array( '%d' ) );
		} else {
			$data = array(
				'job_id'      => (int) $job->id,
				'product_id'  => $product_id,
				'kind'        => $sundry ? 'sundry' : 'part',
				'qty'         => $qty,
				'unit_cost'   => $cost,
				'unit_price'  => $price,
				'location_id' => $location_id,
				'created_by'  => get_current_user_id(),
				'created_at'  => $now,
				'updated_at'  => $now,
			);
			$ok = (bool) $wpdb->insert( $lines_table, $data, self::formats( $data ) );
		}

		if ( $ok ) {
			$ok = false !== $wpdb->update( self::jobs_table(), array( 'updated_at' => $now ), array( 'id' => $job->id ), array( '%s' ), array( '%d' ) );
		}
		if ( ! $ok ) {
			BS_Stock_Ledger::rollback();
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_job_save', 'The job line could not be saved.', array( 'status' => 500 ) );
		}
		BS_Stock_Ledger::commit();
		self::unlock( $job->id );

		BS_Stock_Ledger::after_movement( $product_id );
		return self::get( $job->id );
	}

	/**
	 * Put unused stock back on the shelf. An empty quantity returns the whole line.
	 *
	 * @param int    $job_id Job id.
	 * @param int    $line_id Line id.
	 * @param mixed  $qty Quantity, or empty for all.
	 * @param string $request_key Client key.
	 * @return array|WP_Error
	 */
	public static function return_line( $job_id, $line_id, $qty, $request_key ) {
		global $wpdb;

		$key = self::request_key( $request_key );
		$job = self::row( $job_id );
		if ( ! $job ) {
			return self::not_found();
		}
		if ( ! self::lock( $job->id ) ) {
			return self::busy();
		}
		if ( BS_Stock_Ledger::source_used( $key ) ) {
			self::unlock( $job->id );
			return self::get( $job->id );
		}

		$job     = self::row( $job->id );
		$working = self::require_working( $job );
		if ( is_wp_error( $working ) ) {
			self::unlock( $job->id );
			return $working;
		}

		$lines_table = self::lines_table();
		BS_Stock_Ledger::begin();
		$line = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$lines_table} WHERE id = %d AND job_id = %d FOR UPDATE", (int) $line_id, $job->id ) );
		if ( ! $line ) {
			BS_Stock_Ledger::rollback();
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_job_line', 'That line is not on this job.', array( 'status' => 404 ) );
		}

		if ( null === $qty || '' === $qty ) {
			$amount = (float) $line->qty;
		} else {
			$decimal = 'sundry' === $line->kind || BS_Stock_Ledger::is_sundry( (int) $line->product_id );
			$amount  = BS_Stock_Ledger::normalize_qty( $qty, $decimal );
			if ( ! $decimal && is_numeric( $qty ) && abs( (float) $qty - round( (float) $qty ) ) > 0.0001 ) {
				$amount = null;
			}
			if ( null === $amount || $amount <= 0 ) {
				BS_Stock_Ledger::rollback();
				self::unlock( $job->id );
				return new WP_Error( 'bs_stock_qty', 'Enter a quantity greater than zero.', array( 'status' => 400 ) );
			}
		}

		$moved = self::return_qty( $job, $line, $amount, $key );
		if ( ! is_wp_error( $moved ) ) {
			$saved = $wpdb->update( self::jobs_table(), array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $job->id ), array( '%s' ), array( '%d' ) );
			if ( false === $saved ) {
				$moved = new WP_Error( 'bs_stock_job_save', 'The job could not be saved.', array( 'status' => 500 ) );
			}
		}
		if ( is_wp_error( $moved ) ) {
			BS_Stock_Ledger::rollback();
			self::unlock( $job->id );
			return $moved;
		}
		BS_Stock_Ledger::commit();
		self::unlock( $job->id );

		if ( $moved ) {
			BS_Stock_Ledger::after_movement( (int) $moved );
		}
		return self::get( $job->id );
	}

	/**
	 * Inside an open transaction: write the 'in' movement and shrink or delete the line.
	 *
	 * @param object $job Job row.
	 * @param object $line Line row, locked.
	 * @param float  $qty Quantity to return.
	 * @param string $key Source key.
	 * @return int|WP_Error Product id that moved, or 0 when nothing was held.
	 */
	private static function return_qty( $job, $line, $qty, $key ) {
		global $wpdb;

		$held = (float) $line->qty;
		if ( $held <= 0.0001 ) {
			$wpdb->delete( self::lines_table(), array( 'id' => $line->id ), array( '%d' ) );
			return 0;
		}
		if ( $qty > $held + 0.0001 ) {
			return new WP_Error( 'bs_stock_job_return', sprintf( 'Only %s was booked to this job.', BS_Stock_Ledger::format_qty( $held ) ), array( 'status' => 400 ) );
		}

		$product = BS_Stock_Ledger::countable_product( (int) $line->product_id );
		if ( is_wp_error( $product ) ) {
			return $product;
		}

		$moved = BS_Stock_Ledger::record_job(
			array(
				'type'        => 'in',
				'product'     => $product,
				'location_id' => (int) $line->location_id,
				'qty'         => $qty,
				'note'        => 'Returned from job ' . $job->job_number,
				'job_id'      => $job->id,
				'source_key'  => $key,
			)
		);
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		$left = round( $held - $qty, 2 );
		if ( $left <= 0.0001 ) {
			$ok = (bool) $wpdb->delete( self::lines_table(), array( 'id' => $line->id ), array( '%d' ) );
		} else {
			$ok = false !== $wpdb->update(
				self::lines_table(),
				array(
					'qty'        => $left,
					'updated_at' => current_time( 'mysql' ),
				),
				array( 'id' => $line->id ),
				array( '%f', '%s' ),
				array( '%d' )
			);
		}
		if ( ! $ok ) {
			return new WP_Error( 'bs_stock_job_save', 'The job line could not be saved.', array( 'status' => 500 ) );
		}

		return (int) $line->product_id;
	}

	/**
	 * @param int    $job_id Job id.
	 * @param int    $user_id Mechanic.
	 * @param string $work_date Y-m-d, empty for today.
	 * @param mixed  $hours Hours.
	 * @param string $note Note.
	 * @return array|WP_Error
	 */
	public static function add_labour( $job_id, $user_id, $work_date, $hours, $note ) {
		global $wpdb;

		$job = self::row( $job_id );
		if ( ! $job ) {
			return self::not_found();
		}
		$working = self::require_working( $job );
		if ( is_wp_error( $working ) ) {
			return $working;
		}

		$user_id = (int) $user_id;
		if ( ! self::is_mechanic( $user_id ) ) {
			return new WP_Error( 'bs_stock_mechanic', 'Choose a mechanic who can use the stock app.', array( 'status' => 400 ) );
		}

		$work_date = trim( (string) $work_date );
		if ( '' === $work_date ) {
			$work_date = current_time( 'Y-m-d' );
		}
		if ( ! self::valid_date( $work_date ) ) {
			return new WP_Error( 'bs_stock_date', 'Enter the date as YYYY-MM-DD.', array( 'status' => 400 ) );
		}

		if ( ! is_numeric( $hours ) || (float) $hours <= 0 || (float) $hours > 24 ) {
			return new WP_Error( 'bs_stock_hours', 'Enter hours between 0 and 24, like 1.5.', array( 'status' => 400 ) );
		}

		$note = trim( sanitize_text_field( (string) $note ) );
		$note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 500 ) : substr( $note, 0, 500 );
		$data = array(
			'job_id'      => (int) $job->id,
			'user_id'     => $user_id,
			'work_date'   => $work_date,
			'hours'       => round( (float) $hours, 2 ),
			'cost_rate'   => self::rate_for( $user_id, 'cost' ),
			'charge_rate' => self::rate_for( $user_id, 'charge' ),
			'note'        => $note,
			'created_by'  => get_current_user_id(),
			'created_at'  => current_time( 'mysql' ),
		);
		if ( ! $wpdb->insert( self::labour_table(), $data, self::formats( $data ) ) ) {
			return new WP_Error( 'bs_stock_job_save', 'The labour could not be saved.', array( 'status' => 500 ) );
		}

		$header = array( 'updated_at' => current_time( 'mysql' ) );
		$ids    = self::id_list( $job->mechanic_ids );
		if ( ! in_array( $user_id, $ids, true ) ) {
			$ids[]                  = $user_id;
			$header['mechanic_ids'] = implode( ',', $ids );
		}
		$wpdb->update( self::jobs_table(), $header, array( 'id' => $job->id ), self::formats( $header ), array( '%d' ) );

		return self::get( $job->id );
	}

	/**
	 * @param int $job_id Job id.
	 * @param int $labour_id Labour entry id.
	 * @return array|WP_Error
	 */
	public static function delete_labour( $job_id, $labour_id ) {
		global $wpdb;

		$job = self::row( $job_id );
		if ( ! $job ) {
			return self::not_found();
		}
		$working = self::require_working( $job );
		if ( is_wp_error( $working ) ) {
			return $working;
		}

		$deleted = $wpdb->delete(
			self::labour_table(),
			array(
				'id'     => (int) $labour_id,
				'job_id' => (int) $job->id,
			),
			array( '%d', '%d' )
		);
		if ( ! $deleted ) {
			return new WP_Error( 'bs_stock_job_labour', 'That labour entry is not on this job.', array( 'status' => 404 ) );
		}
		$wpdb->update( self::jobs_table(), array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $job->id ), array( '%s' ), array( '%d' ) );

		return self::get( $job->id );
	}

	/**
	 * Turn a completed job into a pending-payment WooCommerce order. Stock already left on the job, so the order never reduces it again.
	 *
	 * @param int $job_id Job id.
	 * @return array|WP_Error
	 */
	public static function invoice( $job_id ) {
		global $wpdb;

		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'bs_stock_woocommerce', 'WooCommerce is not available.', array( 'status' => 500 ) );
		}

		$job = self::row( $job_id );
		if ( ! $job ) {
			return self::not_found();
		}
		if ( ! self::lock( $job->id ) ) {
			return self::busy();
		}

		$job = self::row( $job->id );
		if ( (int) $job->order_id ) {
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_job_invoiced', 'This job already has WooCommerce order #' . (int) $job->order_id . '.', array( 'status' => 409 ) );
		}
		if ( 'completed' !== $job->status ) {
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_job_status', 'Mark the job completed before creating the order.', array( 'status' => 400 ) );
		}

		$lines  = self::line_rows( $job->id );
		$labour = self::labour_rows( $job->id );
		if ( ! $lines && ! $labour ) {
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_job_empty', 'There is nothing on this job to invoice.', array( 'status' => 400 ) );
		}

		$order = null;
		try {
			$order = wc_create_order(
				array(
					'status'      => 'pending',
					'created_via' => 'bikesense-stock',
				)
			);
			if ( is_wp_error( $order ) ) {
				self::unlock( $job->id );
				return new WP_Error( 'bs_stock_job_order', 'WooCommerce could not create the order: ' . $order->get_error_message(), array( 'status' => 500 ) );
			}

			$order->update_meta_data( self::ORDER_META_JOB, (int) $job->id );
			$order->update_meta_data( self::ORDER_META_NUM, $job->job_number );

			foreach ( $lines as $line ) {
				$qty = (float) $line->qty;
				if ( $qty <= 0 ) {
					continue;
				}
				$product = wc_get_product( (int) $line->product_id );
				if ( ! $product ) {
					throw new Exception( 'A part on this job is no longer in the shop catalogue.' );
				}

				$whole    = abs( $qty - round( $qty ) ) < 0.001;
				$item_qty = $whole ? (int) round( $qty ) : 1;
				$total    = round( (float) $line->unit_price * $qty, 2 );
				$item     = new WC_Order_Item_Product();
				$item->set_product( $product );
				$item->set_quantity( $item_qty );
				$item->set_subtotal( $total );
				$item->set_total( $total );
				if ( ! $whole ) {
					$item->set_name( $product->get_name() . ' (' . BS_Stock_Ledger::qty_label( $qty, BS_Stock_Ledger::unit( $product->get_id() ) ) . ')' );
				}
				$item->add_meta_data( '_reduced_stock', $item_qty, true );
				$item->add_meta_data( '_bs_stock_job_line', (int) $line->id, true );
				$order->add_item( $item );
			}

			$hours  = 0.0;
			$charge = 0.0;
			foreach ( $labour as $entry ) {
				$hours += (float) $entry->hours;
				if ( null !== $entry->charge_rate ) {
					$charge += (float) $entry->hours * (float) $entry->charge_rate;
				}
			}
			if ( $charge > 0 ) {
				$fee = new WC_Order_Item_Fee();
				$fee->set_name( 'Workshop labour, ' . BS_Stock_Ledger::format_qty( $hours ) . ' h' );
				$fee->set_amount( round( $charge, 2 ) );
				$fee->set_total( round( $charge, 2 ) );
				$fee->set_tax_status( 'none' );
				$order->add_item( $fee );
			}

			$parts = preg_split( '/\s+/', trim( $job->customer_name ), 2 );
			$order->set_billing_first_name( isset( $parts[0] ) ? $parts[0] : '' );
			$order->set_billing_last_name( isset( $parts[1] ) ? $parts[1] : '' );
			$order->set_billing_phone( $job->customer_phone );
			if ( is_email( $job->customer_email ) ) {
				$order->set_billing_email( $job->customer_email );
			}

			$order->calculate_totals( false );
			$order->save();
			$order->add_order_note( 'Created from job card ' . $job->job_number . '. Stock was already booked out on the job, so this order does not reduce stock again.' );
		} catch ( Exception $e ) {
			if ( $order && ! is_wp_error( $order ) && $order->get_id() ) {
				$order->delete( true );
			}
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_job_order', $e->getMessage(), array( 'status' => 500 ) );
		}

		$saved = $wpdb->update(
			self::jobs_table(),
			array(
				'status'     => 'invoiced',
				'order_id'   => $order->get_id(),
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $job->id ),
			array( '%s', '%d', '%s' ),
			array( '%d' )
		);
		if ( false === $saved ) {
			$order->delete( true );
			self::unlock( $job->id );
			return new WP_Error( 'bs_stock_job_save', 'The order was not kept because the job could not be updated.', array( 'status' => 500 ) );
		}
		self::unlock( $job->id );

		return self::get( $job->id );
	}

	/**
	 * Full job for the app and the bots.
	 *
	 * @param int    $id Job id.
	 * @param string $number Job number.
	 * @return array|WP_Error
	 */
	public static function get( $id = 0, $number = '' ) {
		$job = $id ? self::row( $id ) : self::row_by_number( $number );
		if ( ! $job ) {
			return self::not_found();
		}

		return self::assemble( $job, true );
	}

	/**
	 * @param object $job Job row.
	 * @param bool   $with_movements Include the stock movements.
	 * @return array
	 */
	private static function assemble( $job, $with_movements ) {
		$lines  = array();
		$labour = array();
		foreach ( self::line_rows( $job->id ) as $row ) {
			$lines[] = self::format_line( $row );
		}
		foreach ( self::labour_rows( $job->id ) as $row ) {
			$labour[] = self::format_labour( $row );
		}

		$payload             = self::format_header( $job );
		$payload['lines']    = $lines;
		$payload['labour']   = $labour;
		$payload['costing']  = self::costing( $lines, $labour );
		$payload['summary']  = self::summary_text( $payload );
		if ( $with_movements ) {
			$moves                        = BS_Stock_Ledger::movements( 0, 100, (int) $job->id );
			$payload['movements']         = $moves['movements'];
			$payload['default_location_id'] = BS_Stock_Ledger::shop_location_id();
		}

		return $payload;
	}

	/**
	 * @param object $job Job row.
	 * @return array
	 */
	private static function format_header( $job ) {
		$mechanics = array();
		foreach ( self::id_list( $job->mechanic_ids ) as $user_id ) {
			$user        = get_userdata( $user_id );
			$mechanics[] = array(
				'id'   => $user_id,
				'name' => $user ? $user->display_name : 'Removed user',
			);
		}

		$order_url = '';
		if ( (int) $job->order_id && function_exists( 'wc_get_order' ) ) {
			$order     = wc_get_order( (int) $job->order_id );
			$order_url = $order ? $order->get_edit_order_url() : '';
		}

		$creator   = $job->created_by ? get_userdata( (int) $job->created_by ) : false;
		$completer = $job->completed_by ? get_userdata( (int) $job->completed_by ) : false;

		return array(
			'id'                => (int) $job->id,
			'job_number'        => $job->job_number,
			'status'            => $job->status,
			'status_label'      => self::status_label( $job->status ),
			'customer_name'     => $job->customer_name,
			'customer_phone'    => $job->customer_phone,
			'customer_email'    => $job->customer_email,
			'bike_make'         => $job->bike_make,
			'bike_model'        => $job->bike_model,
			'bike_year'         => $job->bike_year,
			'bike'              => self::bike_label( $job ),
			'registration'      => $job->registration,
			'vin'               => $job->vin,
			'odometer'          => null === $job->odometer ? null : (int) $job->odometer,
			'description'       => (string) $job->description,
			'notes'             => (string) $job->notes,
			'mechanics'         => $mechanics,
			'mechanic_ids'      => self::id_list( $job->mechanic_ids ),
			'order_id'          => (int) $job->order_id,
			'order_url'         => $order_url,
			'working'           => self::is_working( $job->status ),
			'can_invoice'       => 'completed' === $job->status && ! (int) $job->order_id,
			'created_by'        => (int) $job->created_by,
			'created_by_name'   => $creator ? $creator->display_name : '',
			'created_at'        => $job->created_at,
			'created_label'     => mysql2date( 'j M Y, H:i', $job->created_at ),
			'updated_at'        => $job->updated_at,
			'completed_by'      => (int) $job->completed_by,
			'completed_by_name' => $completer ? $completer->display_name : '',
			'completed_at'      => $job->completed_at,
			'completed_label'   => $job->completed_at ? mysql2date( 'j M Y', $job->completed_at ) : '',
			'cancelled_at'      => $job->cancelled_at,
		);
	}

	/**
	 * @param object $row Line row.
	 * @return array
	 */
	private static function format_line( $row ) {
		$product  = function_exists( 'wc_get_product' ) ? wc_get_product( (int) $row->product_id ) : false;
		$location = BS_Stock_Ledger::get_location( (int) $row->location_id );
		$unit     = BS_Stock_Ledger::unit( (int) $row->product_id );
		$qty      = (float) $row->qty;
		$cost     = null === $row->unit_cost ? null : (float) $row->unit_cost;
		$price    = (float) $row->unit_price;

		return array(
			'id'            => (int) $row->id,
			'product_id'    => (int) $row->product_id,
			'name'          => $product ? $product->get_name() : 'Removed part',
			'sku'           => $product ? $product->get_sku() : '',
			'kind'          => 'sundry' === $row->kind ? 'sundry' : 'part',
			'unit'          => $unit,
			'qty'           => $qty,
			'qty_label'     => BS_Stock_Ledger::qty_label( $qty, $unit ),
			'location_id'   => (int) $row->location_id,
			'location_name' => $location ? $location->name : 'Removed location',
			'unit_cost'     => $cost,
			'unit_price'    => $price,
			'cost'          => null === $cost ? null : round( $cost * $qty, 2 ),
			'charge'        => round( $price * $qty, 2 ),
			'cost_missing'  => null === $cost,
			'price_missing' => $price <= 0,
		);
	}

	/**
	 * @param object $row Labour row.
	 * @return array
	 */
	private static function format_labour( $row ) {
		$user   = get_userdata( (int) $row->user_id );
		$hours  = (float) $row->hours;
		$cost   = null === $row->cost_rate ? null : (float) $row->cost_rate;
		$charge = null === $row->charge_rate ? null : (float) $row->charge_rate;

		return array(
			'id'          => (int) $row->id,
			'user_id'     => (int) $row->user_id,
			'mechanic'    => $user ? $user->display_name : 'Removed user',
			'work_date'   => $row->work_date,
			'date_label'  => mysql2date( 'j M Y', $row->work_date ),
			'hours'       => $hours,
			'cost_rate'   => $cost,
			'charge_rate' => $charge,
			'cost'        => null === $cost ? null : round( $cost * $hours, 2 ),
			'charge'      => null === $charge ? null : round( $charge * $hours, 2 ),
			'rate_missing' => null === $cost || null === $charge,
			'note'        => $row->note,
		);
	}

	/**
	 * Lines without a cost price are counted and flagged, never treated as free.
	 *
	 * @param array $lines Formatted lines.
	 * @param array $labour Formatted labour.
	 * @return array
	 */
	public static function costing( $lines, $labour ) {
		$groups = array(
			'part'   => array( 'cost' => 0.0, 'charge' => 0.0, 'lines' => 0, 'missing_cost' => 0 ),
			'sundry' => array( 'cost' => 0.0, 'charge' => 0.0, 'lines' => 0, 'missing_cost' => 0 ),
		);
		foreach ( $lines as $line ) {
			$kind = 'sundry' === $line['kind'] ? 'sundry' : 'part';
			++$groups[ $kind ]['lines'];
			$groups[ $kind ]['charge'] += $line['charge'];
			if ( null === $line['cost'] ) {
				++$groups[ $kind ]['missing_cost'];
			} else {
				$groups[ $kind ]['cost'] += $line['cost'];
			}
		}

		$work = array( 'hours' => 0.0, 'cost' => 0.0, 'charge' => 0.0, 'entries' => 0, 'missing_cost' => 0, 'missing_charge' => 0 );
		foreach ( $labour as $entry ) {
			++$work['entries'];
			$work['hours'] += $entry['hours'];
			if ( null === $entry['cost'] ) {
				++$work['missing_cost'];
			} else {
				$work['cost'] += $entry['cost'];
			}
			if ( null === $entry['charge'] ) {
				++$work['missing_charge'];
			} else {
				$work['charge'] += $entry['charge'];
			}
		}

		foreach ( array( 'part', 'sundry' ) as $kind ) {
			$groups[ $kind ]['cost']   = round( $groups[ $kind ]['cost'], 2 );
			$groups[ $kind ]['charge'] = round( $groups[ $kind ]['charge'], 2 );
		}
		$work['hours']  = round( $work['hours'], 2 );
		$work['cost']   = round( $work['cost'], 2 );
		$work['charge'] = round( $work['charge'], 2 );

		$cost    = round( $groups['part']['cost'] + $groups['sundry']['cost'] + $work['cost'], 2 );
		$charge  = round( $groups['part']['charge'] + $groups['sundry']['charge'] + $work['charge'], 2 );
		$profit  = round( $charge - $cost, 2 );
		$missing = $groups['part']['missing_cost'] + $groups['sundry']['missing_cost'];

		$warnings = array();
		if ( $missing ) {
			$warnings[] = sprintf( '%d %s no cost price, so cost is understated and margin is overstated.', $missing, 1 === $missing ? 'line has' : 'lines have' );
		}
		if ( $work['missing_cost'] ) {
			$warnings[] = sprintf( '%d labour %s no cost rate.', $work['missing_cost'], 1 === $work['missing_cost'] ? 'entry has' : 'entries have' );
		}
		if ( $work['missing_charge'] ) {
			$warnings[] = sprintf( '%d labour %s no charge-out rate and %s not charged.', $work['missing_charge'], 1 === $work['missing_charge'] ? 'entry has' : 'entries have', 1 === $work['missing_charge'] ? 'is' : 'are' );
		}

		return array(
			'currency' => 'ZAR',
			'parts'    => $groups['part'],
			'sundries' => $groups['sundry'],
			'labour'   => $work,
			'total'    => array(
				'cost'           => $cost,
				'charge'         => $charge,
				'gross_profit'   => $profit,
				'margin_percent' => $charge > 0 ? round( $profit / $charge * 100, 1 ) : null,
			),
			'complete' => ! $warnings,
			'warnings' => $warnings,
		);
	}

	/**
	 * One paragraph any bot can read back when the job is finished.
	 *
	 * @param array $job Assembled job.
	 * @return string
	 */
	public static function summary_text( $job ) {
		$who = '' !== $job['customer_name'] ? ' for ' . $job['customer_name'] : '';
		$out = 'Job ' . $job['job_number'] . $who;

		$bike = $job['bike'];
		if ( '' !== $job['registration'] ) {
			$bike .= ( '' === $bike ? 'registration ' : ' (registration ' ) . $job['registration'] . ( '' === $bike ? '' : ')' );
		}
		if ( '' !== $bike ) {
			$out .= ', ' . $bike;
		}

		if ( 'invoiced' === $job['status'] ) {
			$out .= ', completed ' . $job['completed_label'] . ' and invoiced on order #' . $job['order_id'];
		} elseif ( 'completed' === $job['status'] ) {
			$out .= ', completed ' . $job['completed_label'];
		} else {
			$out .= ', ' . strtolower( $job['status_label'] );
		}
		$out .= '.';

		$parts    = array();
		$sundries = array();
		foreach ( $job['lines'] as $line ) {
			if ( 'sundry' === $line['kind'] ) {
				$unit       = trim( $line['unit'] );
				$sundries[] = ( '' === $unit || 'each' === strtolower( $unit ) )
					? BS_Stock_Ledger::format_qty( $line['qty'] ) . ' x ' . $line['name']
					: $line['qty_label'] . ' ' . $line['name'];
			} else {
				$parts[] = BS_Stock_Ledger::format_qty( $line['qty'] ) . ' x ' . $line['name'] . ' (' . self::money( $line['charge'] ) . ')';
			}
		}
		$out .= ' Parts: ' . ( $parts ? implode( ', ', $parts ) : 'none' ) . '.';
		$out .= ' Sundries: ' . ( $sundries ? implode( ', ', $sundries ) : 'none' ) . '.';

		$hours = array();
		foreach ( $job['labour'] as $entry ) {
			$name           = $entry['mechanic'];
			$hours[ $name ] = ( isset( $hours[ $name ] ) ? $hours[ $name ] : 0 ) + $entry['hours'];
		}
		$work = array();
		foreach ( $hours as $name => $total ) {
			$work[] = BS_Stock_Ledger::format_qty( $total ) . ' h ' . $name;
		}
		$out .= ' Labour: ' . ( $work ? implode( ', ', $work ) : 'none' ) . '.';

		$total = $job['costing']['total'];
		$out  .= ' Cost ' . self::money( $total['cost'] ) . ', charge ' . self::money( $total['charge'] );
		if ( null !== $total['margin_percent'] ) {
			$out .= ', margin ' . round( $total['margin_percent'] ) . '%';
		}
		$out .= '.';
		if ( $job['costing']['warnings'] ) {
			$out .= ' Note: ' . implode( ' ', $job['costing']['warnings'] );
		}

		return $out;
	}

	/**
	 * @param string $status Empty for all, 'active' for jobs being worked on, or one status.
	 * @param string $query Customer, phone, registration, VIN, bike, or job number.
	 * @param int    $limit Maximum rows.
	 * @return array|WP_Error
	 */
	public static function list_jobs( $status, $query, $limit ) {
		global $wpdb;

		$where = array( '1=1' );
		$args  = array();
		$status = sanitize_key( (string) $status );
		if ( 'active' === $status ) {
			$where[] = "status IN ('open','in_progress','waiting_parts')";
		} elseif ( '' !== $status && 'all' !== $status ) {
			if ( ! isset( self::statuses()[ $status ] ) ) {
				return new WP_Error( 'bs_stock_job_status', 'Choose a valid job status.', array( 'status' => 400 ) );
			}
			$where[] = 'status = %s';
			$args[]  = $status;
		}

		$query = trim( sanitize_text_field( (string) $query ) );
		if ( '' !== $query ) {
			$like    = '%' . $wpdb->esc_like( $query ) . '%';
			$compact = '%' . $wpdb->esc_like( str_replace( ' ', '', $query ) ) . '%';
			$where[] = '(job_number LIKE %s OR customer_name LIKE %s OR customer_phone LIKE %s OR REPLACE(registration, \' \', \'\') LIKE %s OR vin LIKE %s OR bike_make LIKE %s OR bike_model LIKE %s)';
			array_push( $args, $like, $like, $like, $compact, $like, $like, $like );
		}

		$limit  = $limit ? max( 1, min( 100, (int) $limit ) ) : 30;
		$args[] = $limit;
		$table  = self::jobs_table();
		$sql    = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d';
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$jobs = array();
		foreach ( (array) $rows as $row ) {
			$jobs[] = self::format_header( $row );
		}

		return array( 'jobs' => $jobs );
	}

	/**
	 * Totals for jobs completed (or opened, when not completed) between two dates.
	 *
	 * @param string $from Y-m-d, empty for the first of this month.
	 * @param string $to Y-m-d, empty for today.
	 * @param string $status Empty skips cancelled jobs, 'all' includes them.
	 * @return array|WP_Error
	 */
	public static function costing_report( $from, $to, $status ) {
		global $wpdb;

		$from = '' === trim( (string) $from ) ? current_time( 'Y-m-01' ) : trim( (string) $from );
		$to   = '' === trim( (string) $to ) ? current_time( 'Y-m-d' ) : trim( (string) $to );
		if ( ! self::valid_date( $from ) || ! self::valid_date( $to ) ) {
			return new WP_Error( 'bs_stock_date', 'Enter dates as YYYY-MM-DD.', array( 'status' => 400 ) );
		}
		if ( $from > $to ) {
			return new WP_Error( 'bs_stock_date', 'The start date is after the end date.', array( 'status' => 400 ) );
		}

		$table  = self::jobs_table();
		$where  = 'DATE(COALESCE(completed_at, created_at)) BETWEEN %s AND %s';
		$args   = array( $from, $to );
		$status = sanitize_key( (string) $status );
		if ( '' === $status ) {
			$where .= " AND status != 'cancelled'";
		} elseif ( 'active' === $status ) {
			$where .= " AND status IN ('open','in_progress','waiting_parts')";
		} elseif ( 'all' !== $status ) {
			if ( ! isset( self::statuses()[ $status ] ) ) {
				return new WP_Error( 'bs_stock_job_status', 'Choose a valid job status.', array( 'status' => 400 ) );
			}
			$where .= ' AND status = %s';
			$args[] = $status;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT 1000", $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$sum = array(
			'parts_cost'      => 0.0,
			'parts_charge'    => 0.0,
			'sundries_cost'   => 0.0,
			'sundries_charge' => 0.0,
			'labour_hours'    => 0.0,
			'labour_cost'     => 0.0,
			'labour_charge'   => 0.0,
		);
		$jobs       = array();
		$incomplete = 0;
		foreach ( (array) $rows as $row ) {
			$job                     = self::assemble( $row, false );
			$c                       = $job['costing'];
			$sum['parts_cost']      += $c['parts']['cost'];
			$sum['parts_charge']    += $c['parts']['charge'];
			$sum['sundries_cost']   += $c['sundries']['cost'];
			$sum['sundries_charge'] += $c['sundries']['charge'];
			$sum['labour_hours']    += $c['labour']['hours'];
			$sum['labour_cost']     += $c['labour']['cost'];
			$sum['labour_charge']   += $c['labour']['charge'];
			if ( ! $c['complete'] ) {
				++$incomplete;
			}
			$jobs[] = array(
				'id'             => $job['id'],
				'job_number'     => $job['job_number'],
				'status'         => $job['status'],
				'customer_name'  => $job['customer_name'],
				'bike'           => $job['bike'],
				'completed_at'   => $job['completed_at'],
				'cost'           => $c['total']['cost'],
				'charge'         => $c['total']['charge'],
				'gross_profit'   => $c['total']['gross_profit'],
				'margin_percent' => $c['total']['margin_percent'],
				'complete'       => $c['complete'],
			);
		}

		foreach ( $sum as $key => $value ) {
			$sum[ $key ] = round( $value, 2 );
		}
		$cost   = round( $sum['parts_cost'] + $sum['sundries_cost'] + $sum['labour_cost'], 2 );
		$charge = round( $sum['parts_charge'] + $sum['sundries_charge'] + $sum['labour_charge'], 2 );

		return array_merge(
			array(
				'from'     => $from,
				'to'       => $to,
				'status'   => '' === $status ? 'all except cancelled' : $status,
				'currency' => 'ZAR',
				'count'    => count( $jobs ),
			),
			$sum,
			array(
				'total_cost'                => $cost,
				'total_charge'              => $charge,
				'gross_profit'              => round( $charge - $cost, 2 ),
				'margin_percent'            => $charge > 0 ? round( ( $charge - $cost ) / $charge * 100, 1 ) : null,
				'jobs_with_missing_costs'   => $incomplete,
				'note'                      => $incomplete ? $incomplete . ' job(s) have parts without a cost price or labour without a rate, so cost is understated.' : '',
				'jobs'                      => $jobs,
			)
		);
	}

	/**
	 * @return array
	 */
	public static function default_rates() {
		return array(
			'cost_rate'   => self::rate_value( get_option( 'bikesense_stock_labour_cost_rate', '' ) ),
			'charge_rate' => self::rate_value( get_option( 'bikesense_stock_labour_charge_rate', '' ) ),
		);
	}

	/**
	 * @param mixed $cost Cost rate, empty clears.
	 * @param mixed $charge Charge-out rate, empty clears.
	 * @return array|WP_Error
	 */
	public static function update_default_rates( $cost, $charge ) {
		$cost   = self::clean_rate( $cost );
		$charge = self::clean_rate( $charge );
		if ( is_wp_error( $cost ) ) {
			return $cost;
		}
		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		update_option( 'bikesense_stock_labour_cost_rate', $cost );
		update_option( 'bikesense_stock_labour_charge_rate', $charge );
		return self::default_rates();
	}

	/**
	 * @return array
	 */
	public static function mechanics() {
		$users = get_users(
			array(
				'capability' => 'manage_woocommerce',
				'orderby'    => 'display_name',
				'number'     => 200,
			)
		);

		$list = array();
		foreach ( (array) $users as $user ) {
			$list[] = self::format_mechanic( $user->ID );
		}

		return array(
			'mechanics' => $list,
			'defaults'  => self::default_rates(),
		);
	}

	/**
	 * @param int   $user_id Mechanic.
	 * @param mixed $cost Cost rate, empty uses the shop default.
	 * @param mixed $charge Charge-out rate, empty uses the shop default.
	 * @return array|WP_Error
	 */
	public static function update_mechanic_rates( $user_id, $cost, $charge ) {
		$user_id = (int) $user_id;
		if ( ! self::is_mechanic( $user_id ) ) {
			return new WP_Error( 'bs_stock_mechanic', 'Choose a mechanic who can use the stock app.', array( 'status' => 400 ) );
		}

		$cost   = self::clean_rate( $cost );
		$charge = self::clean_rate( $charge );
		if ( is_wp_error( $cost ) ) {
			return $cost;
		}
		if ( is_wp_error( $charge ) ) {
			return $charge;
		}

		foreach ( array( self::META_COST_RATE => $cost, self::META_CHARGE_RATE => $charge ) as $key => $value ) {
			if ( '' === $value ) {
				delete_user_meta( $user_id, $key );
			} else {
				update_user_meta( $user_id, $key, $value );
			}
		}

		return self::mechanics();
	}

	/**
	 * @param int $user_id User id.
	 * @return array
	 */
	private static function format_mechanic( $user_id ) {
		$user     = get_userdata( $user_id );
		$defaults = self::default_rates();
		$cost     = self::rate_value( get_user_meta( $user_id, self::META_COST_RATE, true ) );
		$charge   = self::rate_value( get_user_meta( $user_id, self::META_CHARGE_RATE, true ) );

		return array(
			'id'          => (int) $user_id,
			'name'        => $user ? $user->display_name : 'Removed user',
			'cost_rate'   => $cost,
			'charge_rate' => $charge,
			'effective_cost_rate'   => null === $cost ? $defaults['cost_rate'] : $cost,
			'effective_charge_rate' => null === $charge ? $defaults['charge_rate'] : $charge,
		);
	}

	/**
	 * The mechanic's own rate, else the shop default, else null.
	 *
	 * @param int    $user_id User id.
	 * @param string $kind cost or charge.
	 * @return float|null
	 */
	private static function rate_for( $user_id, $kind ) {
		$mechanic = self::format_mechanic( $user_id );
		return 'cost' === $kind ? $mechanic['effective_cost_rate'] : $mechanic['effective_charge_rate'];
	}

	/**
	 * @param int $user_id User id.
	 * @return bool
	 */
	private static function is_mechanic( $user_id ) {
		return $user_id > 0 && get_userdata( $user_id ) && user_can( $user_id, 'manage_woocommerce' );
	}

	/**
	 * @param mixed $raw Stored rate.
	 * @return float|null
	 */
	private static function rate_value( $raw ) {
		return ( '' === $raw || false === $raw || null === $raw || ! is_numeric( $raw ) ) ? null : (float) $raw;
	}

	/**
	 * @param mixed $raw Submitted rate.
	 * @return string|WP_Error Empty string or a two-decimal number.
	 */
	private static function clean_rate( $raw ) {
		if ( null === $raw || '' === $raw ) {
			return '';
		}
		if ( ! is_numeric( $raw ) || (float) $raw < 0 || (float) $raw > 100000 ) {
			return new WP_Error( 'bs_stock_rate', 'Enter an hourly rate in rand, or leave it blank.', array( 'status' => 400 ) );
		}

		return number_format( round( (float) $raw, 2 ), 2, '.', '' );
	}

	/**
	 * @param array $fields Submitted fields.
	 * @param bool  $creating Customer name is required on a new job.
	 * @return array|WP_Error Columns to save.
	 */
	private static function clean_header( $fields, $creating ) {
		foreach ( $fields as $key => $value ) {
			if ( 'mechanic_ids' !== $key && null !== $value && ! is_scalar( $value ) ) {
				$fields[ $key ] = '';
			}
		}

		$data = array();
		$text = array(
			'customer_name'  => 191,
			'customer_phone' => 40,
			'bike_make'      => 64,
			'bike_model'     => 100,
			'registration'   => 32,
			'vin'            => 64,
		);
		foreach ( $text as $key => $max ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}
			$value = trim( sanitize_text_field( (string) $fields[ $key ] ) );
			$value = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
			if ( 'registration' === $key || 'vin' === $key ) {
				$value = strtoupper( $value );
			}
			$data[ $key ] = $value;
		}

		if ( ( $creating || array_key_exists( 'customer_name', $data ) ) && empty( $data['customer_name'] ) ) {
			return new WP_Error( 'bs_stock_job_customer', 'Enter the customer name.', array( 'status' => 400 ) );
		}

		if ( array_key_exists( 'customer_email', $fields ) ) {
			$raw   = trim( (string) $fields['customer_email'] );
			$email = sanitize_email( $raw );
			if ( '' !== $raw && ! is_email( $email ) ) {
				return new WP_Error( 'bs_stock_job_email', 'Check the email address, or leave it blank.', array( 'status' => 400 ) );
			}
			$data['customer_email'] = $email;
		}

		if ( array_key_exists( 'bike_year', $fields ) ) {
			$year = trim( (string) $fields['bike_year'] );
			if ( '' !== $year && ( ! ctype_digit( $year ) || (int) $year < 1900 || (int) $year > (int) current_time( 'Y' ) + 1 ) ) {
				return new WP_Error( 'bs_stock_job_year', 'Enter the bike year like 2019, or leave it blank.', array( 'status' => 400 ) );
			}
			$data['bike_year'] = $year;
		}

		if ( array_key_exists( 'odometer', $fields ) ) {
			$odo = preg_replace( '/[\s,]/', '', (string) $fields['odometer'] );
			if ( '' === $odo || null === $odo ) {
				$data['odometer'] = null;
			} elseif ( ! ctype_digit( $odo ) || (int) $odo > 9999999 ) {
				return new WP_Error( 'bs_stock_job_odometer', 'Enter the odometer reading in whole kilometres, or leave it blank.', array( 'status' => 400 ) );
			} else {
				$data['odometer'] = (int) $odo;
			}
		}

		foreach ( array( 'description', 'notes' ) as $key ) {
			if ( array_key_exists( $key, $fields ) ) {
				$value        = trim( sanitize_textarea_field( (string) $fields[ $key ] ) );
				$data[ $key ] = function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 5000 ) : substr( $value, 0, 5000 );
			}
		}

		if ( array_key_exists( 'mechanic_ids', $fields ) ) {
			$ids = array();
			foreach ( self::id_list( $fields['mechanic_ids'] ) as $user_id ) {
				if ( ! self::is_mechanic( $user_id ) ) {
					return new WP_Error( 'bs_stock_mechanic', 'Choose mechanics who can use the stock app.', array( 'status' => 400 ) );
				}
				$ids[] = $user_id;
			}
			$data['mechanic_ids'] = substr( implode( ',', $ids ), 0, 191 );
		}

		return $data;
	}

	/**
	 * @param mixed $raw Comma list or array of ids.
	 * @return int[]
	 */
	private static function id_list( $raw ) {
		$items = is_array( $raw ) ? $raw : explode( ',', (string) $raw );
		$ids   = array();
		foreach ( $items as $item ) {
			$id = (int) $item;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param object $job Job row.
	 * @return string
	 */
	private static function bike_label( $job ) {
		return trim( implode( ' ', array_filter( array( $job->bike_make, $job->bike_model, $job->bike_year ), 'strlen' ) ) );
	}

	/**
	 * @param int $job_id Job id.
	 * @return array<int, object>
	 */
	private static function line_rows( $job_id ) {
		global $wpdb;

		$table = self::lines_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d AND qty > 0 ORDER BY kind ASC, id ASC", $job_id ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param int $job_id Job id.
	 * @return array<int, object>
	 */
	private static function labour_rows( $job_id ) {
		global $wpdb;

		$table = self::labour_table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE job_id = %d ORDER BY work_date ASC, id ASC", $job_id ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param mixed $raw Client key.
	 * @return string
	 */
	private static function request_key( $raw ) {
		$key = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $raw );
		if ( '' === $key ) {
			$key = wp_generate_password( 20, false, false );
		}

		return 'job:' . substr( $key, 0, 64 );
	}

	/**
	 * Serialise stock changes on one job so two taps cannot race past the idempotency check.
	 *
	 * @param int $job_id Job id.
	 * @return bool
	 */
	private static function lock( $job_id ) {
		global $wpdb;
		$job_id     = (int) $job_id;
		$suppressed = $wpdb->suppress_errors( true );
		$got        = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $wpdb->prefix . 'bs_stock_job_' . $job_id ) );
		$wpdb->suppress_errors( $suppressed );
		if ( '1' === (string) $got ) {
			self::$option_locks[ $job_id ] = false;
			return true;
		}
		if ( '0' === (string) $got ) {
			return false;
		}

		// GET_LOCK is MySQL-only. add_option() is atomic on the unique option_name key.
		$key      = 'bs_stock_job_lock_' . $job_id;
		$deadline = microtime( true ) + 10;
		do {
			if ( add_option( $key, time(), '', 'no' ) ) {
				self::$option_locks[ $job_id ] = true;
				return true;
			}
			wp_cache_delete( $key, 'options' );
			$since = (int) get_option( $key, 0 );
			if ( $since && $since < time() - 60 ) {
				delete_option( $key );
				continue;
			}
			usleep( 200000 );
		} while ( microtime( true ) < $deadline );

		return false;
	}

	/**
	 * @param int $job_id Job id.
	 */
	private static function unlock( $job_id ) {
		global $wpdb;
		$job_id = (int) $job_id;
		if ( ! empty( self::$option_locks[ $job_id ] ) ) {
			delete_option( 'bs_stock_job_lock_' . $job_id );
		} else {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $wpdb->prefix . 'bs_stock_job_' . $job_id ) );
		}
		unset( self::$option_locks[ $job_id ] );
	}

	/**
	 * @param string $date Date.
	 * @return bool
	 */
	private static function valid_date( $date ) {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $date, $m ) ) {
			return false;
		}

		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] );
	}

	/**
	 * @param float $amount Rand.
	 * @return string
	 */
	public static function money( $amount ) {
		return 'R' . number_format( (float) $amount, 2, '.', '' );
	}

	/**
	 * @param array $data Columns.
	 * @return string[]
	 */
	private static function formats( $data ) {
		$ints   = array( 'job_id', 'product_id', 'location_id', 'user_id', 'order_id', 'created_by', 'completed_by', 'odometer' );
		$floats = array( 'qty', 'unit_cost', 'unit_price', 'hours', 'cost_rate', 'charge_rate' );
		$out    = array();
		foreach ( array_keys( $data ) as $key ) {
			if ( in_array( $key, $ints, true ) ) {
				$out[] = '%d';
			} elseif ( in_array( $key, $floats, true ) ) {
				$out[] = '%f';
			} else {
				$out[] = '%s';
			}
		}

		return $out;
	}

	/**
	 * @param mixed $value Flag value.
	 * @return bool
	 */
	private static function flag( $value ) {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}

	/**
	 * @return WP_Error
	 */
	private static function not_found() {
		return new WP_Error( 'bs_stock_job', 'That job was not found.', array( 'status' => 404 ) );
	}

	/**
	 * @return WP_Error
	 */
	private static function busy() {
		return new WP_Error( 'bs_stock_job_busy', 'This job is being updated. Try again in a moment.', array( 'status' => 409 ) );
	}
}
