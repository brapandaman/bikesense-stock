<?php
/**
 * Email intake for specials. Google Apps Script posts one client per unread "Client Intake" thread.
 *
 * The shared secret lives in wp-config.php (BS_STOCK_INTAKE_SECRET) or in an option created from
 * Stock → Settings. It is never written into the plugin files.
 *
 * @package BikeSenseStock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BS_Stock_Intake {

	const OPTION_SECRET = 'bikesense_stock_intake_secret';
	const PLACEHOLDER   = 'CHANGE_THIS_TO_A_LONG_RANDOM_STRING';

	/**
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bs_stock_intakes';
	}

	/**
	 * A secret is usable only when it is long and is not the setup placeholder.
	 *
	 * @return bool
	 */
	public static function ready() {
		return '' !== self::secret();
	}

	/**
	 * Apps Script calls this without a WordPress login. Fail closed when no secret is configured.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function authorize( $request ) {
		$expected = self::secret();
		$provided = $request->get_header( 'x_intake_secret' );
		$provided = is_string( $provided ) ? $provided : '';
		if ( strlen( $provided ) > 200 ) {
			$provided = '';
		}

		if ( '' === $expected || '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'bs_stock_intake_auth', 'unauthorized', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function log( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || empty( $params['client'] ) || ! is_array( $params['client'] ) ) {
			return new WP_Error( 'bs_stock_intake_payload', 'invalid payload', array( 'status' => 400 ) );
		}

		$clean = self::clean( $params['client'] );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$saved = self::insert( $clean );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		return new WP_REST_Response(
			array(
				'status' => 'success',
				'id'     => $saved,
			),
			200
		);
	}

	/**
	 * Shop staff read the list. Application-password clients can read it the same way they read jobs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function list_items( $request ) {
		global $wpdb;

		$limit = (int) $request->get_param( 'limit' );
		if ( $limit < 1 || $limit > 100 ) {
			$limit = 50;
		}

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'bs_stock_intake_read', 'Specials could not be loaded.', array( 'status' => 500 ) );
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::format( $row );
		}

		return rest_ensure_response(
			array(
				'ready' => self::ready(),
				'items' => $items,
			)
		);
	}

	/**
	 * Reveal or replace the secret. Requires the stock-page nonce, so a read-only bot cannot fetch it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function manage_secret( $request ) {
		if ( defined( 'BS_STOCK_INTAKE_SECRET' ) ) {
			$message = self::ready()
				? 'The intake secret is set on the server. Paste that value into the Apps Script.'
				: 'The server secret is missing or too short. Set BS_STOCK_INTAKE_SECRET to a random string of at least 24 characters.';
			return new WP_Error( 'bs_stock_intake_secret', $message, array( 'status' => 400 ) );
		}

		$params = $request->get_json_params();
		$rotate = is_array( $params ) && ! empty( $params['rotate'] );

		if ( $rotate ) {
			$secret = wp_generate_password( 48, false, false );
			self::store_secret( $secret );
			return rest_ensure_response(
				array(
					'secret' => $secret,
					'ready'  => true,
				)
			);
		}

		$secret = self::secret();
		if ( '' === $secret ) {
			return new WP_Error( 'bs_stock_intake_secret', 'Create an intake secret first.', array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'secret' => $secret,
				'ready'  => true,
			)
		);
	}

	/**
	 * @param array $client Client object from Apps Script.
	 * @return array|WP_Error
	 */
	private static function clean( $client ) {
		$name  = self::text( isset( $client['name'] ) ? $client['name'] : '', 191 );
		$phone = self::phone( isset( $client['phone'] ) ? $client['phone'] : '' );
		if ( '' === $name || '' === $phone ) {
			return new WP_Error( 'bs_stock_intake_required', 'name and phone required', array( 'status' => 400 ) );
		}

		$bike = ( isset( $client['bike'] ) && is_array( $client['bike'] ) ) ? $client['bike'] : array();
		$year = self::text( isset( $bike['year'] ) ? $bike['year'] : '', 4 );
		if ( ! preg_match( '/^\d{4}$/', $year ) ) {
			$year = '';
		}

		$email = '';
		if ( isset( $client['email'] ) && is_scalar( $client['email'] ) ) {
			$email = sanitize_email( trim( (string) $client['email'] ) );
			if ( ! is_email( $email ) ) {
				$email = '';
			}
		}

		return array(
			'message_id'     => self::message_id( isset( $client['message_id'] ) ? $client['message_id'] : '' ),
			'customer_name'  => $name,
			'customer_phone' => $phone,
			'customer_email' => $email,
			'bike_make'      => self::text( isset( $bike['make'] ) ? $bike['make'] : '', 64 ),
			'bike_model'     => self::text( isset( $bike['model'] ) ? $bike['model'] : '', 100 ),
			'bike_year'      => $year,
			'vin'            => strtoupper( self::text( isset( $bike['vin'] ) ? $bike['vin'] : '', 64 ) ),
			'reason'         => self::note( isset( $client['reason'] ) ? $client['reason'] : '' ),
			'source'         => 'email-intake',
		);
	}

	/**
	 * @param array $clean Cleaned columns, without public_id or created_at.
	 * @return string|WP_Error Public id.
	 */
	private static function insert( $clean ) {
		global $wpdb;

		if ( '' !== $clean['message_id'] ) {
			$existing = self::id_for_message( $clean['message_id'] );
			if ( $existing ) {
				return $existing;
			}
		}

		$now = current_time( 'mysql' );
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			$row = array(
				'public_id'      => 'cli_' . wp_generate_password( 16, false, false ),
				'customer_name'  => $clean['customer_name'],
				'customer_phone' => $clean['customer_phone'],
				'customer_email' => $clean['customer_email'],
				'bike_make'      => $clean['bike_make'],
				'bike_model'     => $clean['bike_model'],
				'bike_year'      => $clean['bike_year'],
				'vin'            => $clean['vin'],
				'reason'         => $clean['reason'],
				'source'         => $clean['source'],
				'created_at'     => $now,
			);
			if ( '' !== $clean['message_id'] ) {
				$row['message_id'] = $clean['message_id'];
			}

			$ok = $wpdb->insert( self::table(), $row, self::formats( $row ) );
			if ( $ok ) {
				return $row['public_id'];
			}

			if ( '' !== $clean['message_id'] ) {
				$existing = self::id_for_message( $clean['message_id'] );
				if ( $existing ) {
					return $existing;
				}
			}
		}

		return new WP_Error( 'bs_stock_intake_write', 'write failed', array( 'status' => 500 ) );
	}

	/**
	 * @param object $row Database row.
	 * @return array
	 */
	private static function format( $row ) {
		return array(
			'id'            => $row->public_id,
			'name'          => $row->customer_name,
			'phone'         => $row->customer_phone,
			'email'         => $row->customer_email,
			'bike'          => array(
				'make'  => $row->bike_make,
				'model' => $row->bike_model,
				'year'  => $row->bike_year,
				'vin'   => $row->vin,
			),
			'reason'        => $row->reason,
			'source'        => $row->source,
			'created_at'    => $row->created_at,
			'created_label' => mysql2date( 'j M Y, H:i', $row->created_at ),
		);
	}

	/**
	 * @param string $message_id Gmail message id.
	 * @return string
	 */
	private static function id_for_message( $message_id ) {
		global $wpdb;

		$table = self::table();
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT public_id FROM {$table} WHERE message_id = %s", $message_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return is_string( $found ) ? $found : '';
	}

	/**
	 * @param string $secret New secret.
	 */
	private static function store_secret( $secret ) {
		if ( false === get_option( self::OPTION_SECRET, false ) ) {
			add_option( self::OPTION_SECRET, $secret, '', 'no' );
			return;
		}

		update_option( self::OPTION_SECRET, $secret );
	}

	/**
	 * @return string Empty when intake is not configured.
	 */
	private static function secret() {
		$secret = '';
		if ( defined( 'BS_STOCK_INTAKE_SECRET' ) ) {
			$secret = (string) BS_STOCK_INTAKE_SECRET;
		} else {
			$secret = (string) get_option( self::OPTION_SECRET, '' );
		}

		$secret = trim( $secret );
		if ( strlen( $secret ) < 24 || self::PLACEHOLDER === $secret ) {
			return '';
		}

		return $secret;
	}

	/**
	 * @param mixed $value Raw value.
	 * @param int   $max Maximum characters.
	 * @return string
	 */
	private static function text( $value, $max ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max ) : substr( $value, 0, $max );
	}

	/**
	 * @param mixed $value Raw phone.
	 * @return string
	 */
	private static function phone( $value ) {
		$phone = self::text( $value, 40 );
		$phone = preg_replace( '/[^\d+]/', '', $phone );
		$phone = is_string( $phone ) ? $phone : '';
		if ( strlen( preg_replace( '/\D/', '', $phone ) ) < 8 ) {
			return '';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $phone, 0, 40 ) : substr( $phone, 0, 40 );
	}

	/**
	 * @param mixed $value Raw note.
	 * @return string
	 */
	private static function note( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( sanitize_textarea_field( (string) $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 500 ) : substr( $value, 0, 500 );
	}

	/**
	 * @param mixed $value Raw Gmail id.
	 * @return string
	 */
	private static function message_id( $value ) {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$id = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
		$id = is_string( $id ) ? $id : '';
		return substr( $id, 0, 64 );
	}

	/**
	 * @param array $data Columns.
	 * @return string[]
	 */
	private static function formats( $data ) {
		$out = array();
		foreach ( array_keys( $data ) as $key ) {
			$out[] = '%s';
		}
		return $out;
	}
}
