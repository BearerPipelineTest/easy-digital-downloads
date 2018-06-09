<?php
/**
 * Payment Functions
 *
 * @package     EDD
 * @subpackage  Payments
 * @copyright   Copyright (c) 2018, Easy Digital Downloads, LLC
 * @license     http://opensource.org/licenses/gpl-2.0.php GNU Public License
 * @since       1.0
 */

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Retrieves an instance of EDD_Payment for a specified ID.
 *
 * @since 2.7
 *
 * @param mixed int|EDD_Payment|WP_Post $payment Payment ID, EDD_Payment object or WP_Post object.
 * @param bool                          $by_txn  Is the ID supplied as the first parameter
 *
 * @return EDD_Payment|false false|object EDD_Payment if a valid payment ID, false otherwise.
 */
function edd_get_payment( $payment_or_txn_id = null, $by_txn = false ) {
	global $wpdb;

	if ( $payment_or_txn_id instanceof WP_Post || $payment_or_txn_id instanceof EDD_Payment ) {
		$payment_id = $payment_or_txn_id->ID;
	} elseif ( $by_txn ) {
		if ( empty( $payment_or_txn_id ) ) {
			return false;
		}

		$payment_id = edd_get_order_id_from_transaction_id( $payment_or_txn_id );

		if ( empty( $payment_id ) ) {
			return false;
		}
	} else {
		$payment_id = $payment_or_txn_id;
	}

	if ( empty( $payment_id ) ) {
		return false;
	}

	$cache_key = md5( 'edd_payment' . $payment_id );
	$payment   = wp_cache_get( $cache_key, 'payments' );

	if ( false === $payment ) {
		$payment = new EDD_Payment( $payment_id );
		if ( empty( $payment->ID ) || ( ! $by_txn && (int) $payment->ID !== (int) $payment_id ) ) {
			return false;
		} else {
			wp_cache_set( $cache_key, $payment, 'payments' );
		}
	}

	return $payment;
}

/**
 * Retrieve payments from the database.
 *
 * Since 1.2, this function takes an array of arguments, instead of individual
 * parameters. All of the original parameters remain, but can be passed in any
 * order via the array.
 *
 * $offset = 0, $number = 20, $mode = 'live', $orderby = 'ID', $order = 'DESC',
 * $user = null, $status = 'any', $meta_key = null
 *
 * @since 1.0
 * @since 1.8 Refactored to be a wrapper for EDD_Payments_Query.
 *
 * @param array $args Arguments passed to get payments.
 * @return EDD_Payment[] $payments Payments retrieved from the database.
 */
function edd_get_payments( $args = array() ) {

	// Fallback to post objects to ensure backwards compatibility.
	if ( ! isset( $args['output'] ) ) {
		$args['output'] = 'posts';
	}

	$args     = apply_filters( 'edd_get_payments_args', $args );
	$payments = new EDD_Payments_Query( $args );
	return $payments->get_payments();
}

/**
 * Retrieve payment by a given field
 *
 * @since       2.0
 * @param       string $field The field to retrieve the payment with
 * @param       mixed $value The value for $field
 * @return      mixed
 */
function edd_get_payment_by( $field = '', $value = '' ) {
	$payment = false;

	if ( ! empty( $field ) && ! empty( $value ) ) {
		switch ( strtolower( $field ) ) {
			case 'id':
				$payment = edd_get_payment( $value );

				if ( ! $payment->ID > 0 ) {
					$payment = false;
				}
				break;
			case 'key':
				$order = edd_get_order_by( 'payment_key', $value );

				if ( $order ) {
					$payment = edd_get_payment( $order->get_id() );

					if ( ! $payment->ID > 0 ) {
						$payment = false;
					}
				}
				break;
			case 'payment_number':
				$order = edd_get_order_by( 'order_number', $value );

				if ( $order ) {
					$payment = edd_get_payment( $order->get_id() );

					if ( ! $payment->ID > 0 ) {
						$payment = false;
					}
				}
				break;
		}
	}

	return $payment;
}

/**
 * Insert an order into the database.
 *
 * @since 1.0
 * @since 3.0 Refactored to add orders using new methods.
 *
 * @param array $order_data Order data to process.
 * @return int|bool Order ID if the order was successfully inserted, false otherwise.
 */
function edd_insert_payment( $order_data = array() ) {
	if ( empty( $order_data ) ) {
		return false;
	}

	$resume_payment   = false;
	$existing_payment = EDD()->session->get( 'edd_resume_payment' );

	if ( ! empty( $existing_payment ) ) {
		$payment        = edd_get_payment( $existing_payment );
		$resume_payment = $payment->is_recoverable();
	}

	if ( $resume_payment ) {
		$payment->date = date( 'Y-m-d G:i:s', current_time( 'timestamp' ) );

		$payment->add_note( __( 'Payment recovery processed', 'easy-digital-downloads' ) );

		// Since things could have been added/removed since we first crated this...rebuild the cart details.
		foreach ( $payment->fees as $fee_index => $fee ) {
			$payment->remove_fee_by( 'index', $fee_index, true );
		}

		foreach ( $payment->downloads as $cart_index => $download ) {
			$item_args = array(
				'quantity'   => isset( $download['quantity'] ) ? $download['quantity'] : 1,
				'cart_index' => $cart_index,
			);
			$payment->remove_download( $download['id'], $item_args );
		}

		if ( strtolower( $payment->email ) !== strtolower( $order_data['user_info']['email'] ) ) {

			// Remove the payment from the previous customer.
			$previous_customer = new EDD_Customer( $payment->customer_id );
			$previous_customer->remove_payment( $payment->ID, false );

			// Redefine the email first and last names.
			$payment->email      = $order_data['user_info']['email'];
			$payment->first_name = $order_data['user_info']['first_name'];
			$payment->last_name  = $order_data['user_info']['last_name'];

		}

		// Remove any remainders of possible fees from items.
		$payment->save();
	}

	return edd_build_order( $order_data );
}

/**
 * Updates a payment status.
 *
 * @since 1.0
 * @since 3.0 Updated to use new order methods.
 *
 * @param  int    $order_id Order ID.
 * @param  string $new_status order status (default: publish)
 *
 * @return bool True if the status was updated successfully, false otherwise.
 */
function edd_update_payment_status( $order_id = 0, $new_status = 'publish' ) {
	return edd_transition_order_status( $order_id, $new_status );
}

/**
 * Deletes a Purchase
 *
 * @since 1.0
 * @global $edd_logs
 *
 * @uses EDD_Logging::delete_logs()
 *
 * @param int $payment_id Payment ID (default: 0)
 * @param bool $update_customer If we should update the customer stats (default:true)
 * @param bool $delete_download_logs If we should remove all file download logs associated with the payment (default:false)
 *
 * @return void
 */
function edd_delete_purchase( $payment_id = 0, $update_customer = true, $delete_download_logs = false ) {
	global $edd_logs;

	$payment = edd_get_payment( $payment_id );

	// Update sale counts and earnings for all purchased products
	edd_undo_purchase( false, $payment_id );

	$amount      = edd_get_payment_amount( $payment_id );
	$status      = $payment->post_status;
	$customer_id = edd_get_payment_customer_id( $payment_id );

	$customer = edd_get_customer( $customer_id );

	if ( 'revoked' === $status || 'publish' === $status ) {

		// Only decrease earnings if they haven't already been decreased (or were never increased for this payment).
		edd_decrease_total_earnings( $amount );
		// Clear the This Month earnings (this_monththis_month is NOT a typo)
		delete_transient( md5( 'edd_earnings_this_monththis_month' ) );

		if ( $customer->id && $update_customer ) {

			// Decrement the stats for the customer
			$customer->decrease_purchase_count();
			$customer->decrease_value( $amount );
		}
	}

	do_action( 'edd_payment_delete', $payment_id );

	if ( $customer->id && $update_customer ) {

		// Remove the payment ID from the customer
		$customer->remove_payment( $payment_id );
	}

	// Remove the order.
	edd_delete_order( $payment_id );

	// Delete file download loags.
	if ( $delete_download_logs ) {
		$edd_logs->delete_logs(
			null,
			'file_download',
			array(
				array(
					'key'   => '_edd_log_payment_id',
					'value' => $payment_id
				)
			)
		);
	}

	do_action( 'edd_payment_deleted', $payment_id );
}

/**
 * Undo a purchase, including the decrease of sale and earning stats. Used for
 * when refunding or deleting a purchase.
 *
 * @since 1.0.8.1
 *
 * @param int $download_id Download (Post) ID.
 * @param int $payment_id  Payment ID.
 */
function edd_undo_purchase( $download_id = 0, $payment_id ) {

	/**
	 * In 2.5.7, a bug was found that $download_id was an incorrect usage. Passing it in
	 * now does nothing, but we're holding it in place for legacy support of the argument order.
	 */

	if ( ! empty( $download_id ) ) {
		$download_id = false;
		_edd_deprected_argument( 'download_id', 'edd_undo_purchase', '2.5.7' );
	}

	$payment = edd_get_payment( $payment_id );

	$cart_details = $payment->cart_details;
	$user_info    = $payment->user_info;

	if ( is_array( $cart_details ) ) {
		foreach ( $cart_details as $item ) {

			// Get the item's price.
			$amount = isset( $item['price'] ) ? $item['price'] : false;

			// Decrease earnings/sales and fire action once per quantity number.
			for ( $i = 0; $i < $item['quantity']; $i++ ) {

				// Handle variable priced downloads.
				if ( false === $amount && edd_has_variable_prices( $item['id'] ) ) {
					$price_id = isset( $item['item_number']['options']['price_id'] ) ? $item['item_number']['options']['price_id'] : null;
					$amount   = ! isset( $item['price'] ) && 0 !== $item['price'] ? edd_get_price_option_amount( $item['id'], $price_id ) : $item['price'];
				}

				if ( ! $amount ) {
					// This function is only used on payments with near 1.0 cart data structure.
					$amount = edd_get_download_final_price( $item['id'], $user_info, $amount );
				}
			}

			if ( ! empty( $item['fees'] ) ) {
				foreach ( $item['fees'] as $fee ) {

					// Only let negative fees affect the earnings.
					if ( $fee['amount'] > 0 ) {
						continue;
					}

					$amount += $fee['amount'];
				}
			}

			$maybe_decrease_earnings = apply_filters( 'edd_decrease_earnings_on_undo', true, $payment, $item['id'] );
			if ( true === $maybe_decrease_earnings ) {

				// Decrease earnings.
				edd_decrease_earnings( $item['id'], $amount );
			}

			$maybe_decrease_sales = apply_filters( 'edd_decrease_sales_on_undo', true, $payment, $item['id'] );
			if ( true === $maybe_decrease_sales ) {

				// Decrease purchase count.
				edd_decrease_purchase_count( $item['id'], $item['quantity'] );
			}
		}
	}
}

/**
 * Count Payments
 *
 * Returns the total number of payments recorded.
 *
 * @since 1.0
 * @since 3.0 Refactored to work with edd_orders table.
 *
 * @param array $args List of arguments to base the payments count on.
 *
 * @return array $count Number of payments sorted by payment status.
 */
function edd_count_payments( $args = array() ) {
	global $wpdb;

	$defaults = array(
		'user'       => null,
		'customer'   => null,
		's'          => null,
		'start-date' => null,
		'end-date'   => null,
		'download'   => null,
		'gateway'    => null,
	);

	$args = wp_parse_args( $args, $defaults );

	$select = '';
	$join   = '';
	$where  = '';

	// Count payments for a specific user
	if ( ! empty( $args['user'] ) ) {
		if ( is_email( $args['user'] ) ) {
			$field = 'email';
		} elseif ( is_numeric( $args['user'] ) ) {
			$field = 'user_id';
		} else {
			$field = '';
		}

		if ( ! empty( $field ) ) {
			$where .= " AND {$field} = '{$args['user']}'";
		}
	} elseif ( ! empty( $args['customer'] ) ) {
			$where .= " AND customer_id = '{$args['customer']}'";

		// Count payments for a search
	} elseif ( ! empty( $args['s'] ) ) {
		$args['s'] = sanitize_text_field( $args['s'] );

		if ( is_email( $args['s'] ) || 32 === strlen( $args['s'] ) ) {
			if ( is_email( $args['s'] ) ) {
				$field = 'email';
			} else {
				$field = 'payment_key';
			}

			$where .= $wpdb->prepare( " {$field} = '%s'", $args['s'] );
		} elseif ( '#' == substr( $args['s'], 0, 1 ) ) {
			$search = str_replace( '#:', '', $args['s'] );
			$search = str_replace( '#', '', $search );

			$select = "SELECT p2.post_status,count( * ) AS num_posts ";
			$join   = "LEFT JOIN $wpdb->postmeta m ON m.meta_key = '_edd_log_payment_id' AND m.post_id = p.ID ";
			$join  .= "INNER JOIN $wpdb->posts p2 ON m.meta_value = p2.ID ";
			$where  = "WHERE p.post_type = 'edd_log' ";
			$where .= $wpdb->prepare( "AND p.post_parent = %d ", $search );
		} elseif ( is_numeric( $args['s'] ) ) {
			$join = "LEFT JOIN $wpdb->postmeta m ON (p.ID = m.post_id)";
			$where .= $wpdb->prepare( "
				AND m.meta_key = '_edd_payment_user_id'
				AND m.meta_value = %d",
				$args['s']
			);
		} elseif ( 0 === strpos( $args['s'], 'discount:' ) ) {
			$search = str_replace( 'discount:', '', $args['s'] );
			$search = 'discount.*' . $search;

			$join   = "LEFT JOIN {$wpdb->edd_order_adjustments} oa ON (o.id = oa.order_id)";
			$where .= $wpdb->prepare( "
				AND m.meta_key = '_edd_payment_meta'
				AND m.meta_value REGEXP %s",
				$search
			);
		} else {
			$search = $wpdb->esc_like( $args['s'] );
			$search = '%' . $search . '%';

			$where .= $wpdb->prepare( "AND ((p.post_title LIKE %s) OR (p.post_content LIKE %s))", $search, $search );
		}
	}

	if ( ! empty( $args['download'] ) && is_numeric( $args['download'] ) ) {
		$where .= $wpdb->prepare( " AND p.post_parent = %d", $args['download'] );
	}

	// Limit payments count by gateway
	if ( ! empty( $args['gateway'] ) ) {
		$where .= $wpdb->prepare( " AND gateway = '%s'", $args['gateway'] );
	}

	// Limit payments count by date
	if ( ! empty( $args['start-date'] ) && false !== strpos( $args['start-date'], '/' ) ) {

		$date_parts = explode( '/', $args['start-date'] );
		$month      = ! empty( $date_parts[0] ) && is_numeric( $date_parts[0] ) ? $date_parts[0] : 0;
		$day        = ! empty( $date_parts[1] ) && is_numeric( $date_parts[1] ) ? $date_parts[1] : 0;
		$year       = ! empty( $date_parts[2] ) && is_numeric( $date_parts[2] ) ? $date_parts[2] : 0;

		$is_date    = checkdate( $month, $day, $year );
		if ( false !== $is_date ) {

			$date   = new DateTime( $args['start-date'] );
			$where .= $wpdb->prepare( " AND p.post_date >= '%s'", $date->format( 'Y-m-d' ) );

		}

		// Fixes an issue with the payments list table counts when no end date is specified (partly with stats class)
		if ( empty( $args['end-date'] ) ) {
			$args['end-date'] = $args['start-date'];
		}

	}

	if ( ! empty ( $args['end-date'] ) && false !== strpos( $args['end-date'], '/' ) ) {

		$date_parts = explode( '/', $args['end-date'] );

		$month      = ! empty( $date_parts[0] ) ? $date_parts[0] : 0;
		$day        = ! empty( $date_parts[1] ) ? $date_parts[1] : 0;
		$year       = ! empty( $date_parts[2] ) ? $date_parts[2] : 0;

		$is_date    = checkdate( $month, $day, $year );
		if ( false !== $is_date ) {
			$date = date( 'Y-m-d', strtotime( '+1 day', mktime( 0, 0, 0, $month, $day, $year ) ) );

			$where .= $wpdb->prepare( " AND p.post_date < '%s'", $date );
		}

	}

	$where = apply_filters( 'edd_count_payments_where', $where );
	$join  = apply_filters( 'edd_count_payments_join', $join );

	$query = "
		{$select}
		FROM {$wpdb->edd_orders} o
		{$join}
		{$where}
		GROUP BY o.status
	";

	$cache_key = md5( $query );

	$count = wp_cache_get( $cache_key, 'counts');
	if ( false !== $count ) {
		return $count;
	}

	$count = $wpdb->get_results( $query, ARRAY_A );

	$stats    = array();
	$statuses = get_post_stati();
	if ( isset( $statuses['private'] ) && empty( $args['s'] ) ) {
		unset( $statuses['private'] );
	}

	foreach ( $statuses as $state ) {
		$stats[$state] = 0;
	}

	foreach ( (array) $count as $row ) {
		if ( 'private' == $row['post_status'] && empty( $args['s'] ) ) {
			continue;
		}

		$stats[ $row['post_status'] ] = $row['num_posts'];
	}

	$stats = (object) $stats;
	wp_cache_set( $cache_key, $stats, 'counts' );

	return $stats;
}


/**
 * Check for existing payment.
 *
 * @since 1.0
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return bool True if payment exists, false otherwise.
 */
function edd_check_for_existing_payment( $order_id ) {
	$exists = false;

	$order = edd_get_order( $order_id );

	if ( $order_id === $order->get_id() && $order->is_complete() ) {
		$exists = true;
	}

	return $exists;
}

/**
 * Get order status.
 *
 * @since 1.0
 * @since 3.0 Updated to use new EDD\Order\Order class.
 *
 * @param WP_Post|EDD_Payment|int $order        Payment post object, EDD_Payment object, or payment/post ID.
 * @param bool                    $return_label Whether to return the payment status or not
 *
 * @return bool|mixed if payment status exists, false otherwise
 */
function edd_get_payment_status( $order, $return_label = false ) {
	if ( is_numeric( $order ) ) {
		$order = edd_get_order( $order );

		if ( ! $order ) {
			return false;
		}
	}

	if ( $order instanceof EDD_Payment ) {
		/** @var EDD_Payment $order */
		$order = edd_get_order( $order->id );
	}

	if ( $order instanceof WP_Post ) {
		/** @var WP_Post $order */
		$order = edd_get_order( $order->ID );
	}

	if ( ! is_object( $order ) ) {
		return false;
	}

	$status = $order->get_status();

	if ( empty( $status ) ) {
		return false;
	}

	if ( true === $return_label ) {
		return edd_get_payment_status_label( $status );
	} else {
		$statuses = edd_get_payment_statuses();

		// Account that our 'publish' status is labeled 'Complete'
		$post_status = $order->is_complete()
			? 'Completed'
			: $status;

		// Make sure we're matching cases, since they matter
		return array_search( strtolower( $post_status ), array_map( 'strtolower', $statuses ), true );
	}
}

/**
 * Given a payment status string, return the label for that string.
 *
 * @since 2.9.2
 * @param string $status
 *
 * @return bool|mixed
 */
function edd_get_payment_status_label( $status = '' ) {
	$default  = strtoupper( $status );
	$statuses = edd_get_payment_statuses();

	if ( ! is_array( $statuses ) || empty( $statuses ) ) {
		return $default;
	}

	if ( array_key_exists( $status, $statuses ) ) {
		return $statuses[ $status ];
	}

	return $default;
}

/**
 * Retrieves all available statuses for payments.
 *
 * @since 1.0.8.1
 * @return array $payment_status All the available payment statuses
 */
function edd_get_payment_statuses() {
	return apply_filters( 'edd_payment_statuses', array(
		'pending'    => __( 'Pending',    'easy-digital-downloads' ),
		'processing' => __( 'Processing', 'easy-digital-downloads' ),
		'publish'    => __( 'Completed',  'easy-digital-downloads' ),
		'refunded'   => __( 'Refunded',   'easy-digital-downloads' ),
		'revoked'    => __( 'Revoked',    'easy-digital-downloads' ),
		'failed'     => __( 'Failed',     'easy-digital-downloads' ),
		'abandoned'  => __( 'Abandoned',  'easy-digital-downloads' )
	) );
}

/**
 * Retrieves keys for all available statuses for payments.
 *
 * @since 2.3
 *
 * @return array $payment_status All the available payment statuses.
 */
function edd_get_payment_status_keys() {
	$statuses = array_keys( edd_get_payment_statuses() );
	asort( $statuses );

	return array_values( $statuses );
}

/**
 * Checks whether a payment has been marked as complete.
 *
 * @since 1.0.8
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID to check against.
 * @return bool True if complete, false otherwise.
 */
function edd_is_payment_complete( $order_id = 0 ) {
	$order = edd_get_order( $order_id );

	$ret = false;

	if ( $order ) {
		if ( (int) $order_id === $order->get_id() && $order->is_complete() ) {
			$ret = true;
		}
	}

	return apply_filters( 'edd_is_payment_complete', $ret, $order_id, $order->get_status() );
}

/**
 * Get Total Sales
 *
 * @since 1.2.2
 * @return int $count Total sales
 */
function edd_get_total_sales() {
	$payments = edd_count_payments();
	return $payments->revoked + $payments->publish;
}

/**
 * Get Total Earnings
 *
 * @since 1.2
 * @return float $total Total earnings
 */
function edd_get_total_earnings() {

	$total = get_option( 'edd_earnings_total', false );

	// If no total stored in DB, use old method of calculating total earnings
	if( false === $total ) {

		global $wpdb;

		$total = get_transient( 'edd_earnings_total' );

		if( false === $total ) {

			$total = (float) 0;

			$args = apply_filters( 'edd_get_total_earnings_args', array(
				'offset' => 0,
				'number' => -1,
				'status' => array( 'publish', 'revoked' ),
				'fields' => 'ids'
			) );


			$payments = edd_get_payments( $args );
			if ( $payments ) {

				/*
				 * If performing a purchase, we need to skip the very last payment in the database, since it calls
				 * edd_increase_total_earnings() on completion, which results in duplicated earnings for the very
				 * first purchase
				 */

				if( did_action( 'edd_update_payment_status' ) ) {
					array_pop( $payments );
				}

				if( ! empty( $payments ) ) {
					$payments = implode( ',', $payments );
					$total += $wpdb->get_var( "SELECT SUM(meta_value) FROM $wpdb->postmeta WHERE meta_key = '_edd_payment_total' AND post_id IN({$payments})" );
				}

			}

			// Cache results for 1 day. This cache is cleared automatically when a payment is made
			set_transient( 'edd_earnings_total', $total, 86400 );

			// Store the total for the first time
			update_option( 'edd_earnings_total', $total );
		}
	}

	if( $total < 0 ) {
		$total = 0; // Don't ever show negative earnings
	}

	return apply_filters( 'edd_total_earnings', round( $total, edd_currency_decimal_filter() ) );
}

/**
 * Increase the Total Earnings
 *
 * @since 1.8.4
 * @param $amount int The amount you would like to increase the total earnings by.
 * @return float $total Total earnings
 */
function edd_increase_total_earnings( $amount = 0 ) {
	$total = floatval( edd_get_total_earnings() );
	$total += floatval( $amount );
	update_option( 'edd_earnings_total', $total );
	return $total;
}

/**
 * Decrease the Total Earnings
 *
 * @since 1.8.4
 * @param $amount int The amount you would like to decrease the total earnings by.
 * @return float $total Total earnings
 */
function edd_decrease_total_earnings( $amount = 0 ) {
	$total = edd_get_total_earnings();
	$total -= $amount;
	if( $total < 0 ) {
		$total = 0;
	}
	update_option( 'edd_earnings_total', $total );
	return $total;
}

/**
 * Get Payment Meta for a specific Payment
 *
 * @since 1.2
 * @param int $payment_id Payment ID
 * @param string $meta_key The meta key to pull
 * @param bool $single Pull single meta entry or as an object
 * @return mixed $meta Payment Meta
 */
function edd_get_payment_meta( $payment_id = 0, $meta_key = '_edd_payment_meta', $single = true ) {
	$payment = new EDD_Payment( $payment_id );
	return $payment->get_meta( $meta_key, $single );
}

/**
 * Update the meta for a payment
 * @param  integer $payment_id Payment ID
 * @param  string  $meta_key   Meta key to update
 * @param  string  $meta_value Value to update to
 * @param  string  $prev_value Previous value
 * @return mixed               Meta ID if successful, false if unsuccessful
 */
function edd_update_payment_meta( $payment_id = 0, $meta_key = '', $meta_value = '', $prev_value = '' ) {
	$payment = new EDD_Payment( $payment_id );
	return $payment->update_meta( $meta_key, $meta_value, $prev_value );
}

/**
 * Get the user_info Key from Payment Meta
 *
 * @since 1.2
 * @param int $payment_id Payment ID
 * @return array $user_info User Info Meta Values
 */
function edd_get_payment_meta_user_info( $payment_id ) {
	$payment = new EDD_Payment( $payment_id );
	return $payment->user_info;
}

/**
 * Get the downloads Key from Payment Meta
 *
 * @since 1.2
 * @param int $payment_id Payment ID
 * @return array $downloads Downloads Meta Values
 */
function edd_get_payment_meta_downloads( $payment_id ) {
	$payment = new EDD_Payment( $payment_id );
	return $payment->downloads;
}

/**
 * Get the cart_details Key from Payment Meta
 *
 * @since 1.2
 * @param int $payment_id Payment ID
 * @param bool $include_bundle_files Whether to retrieve product IDs associated with a bundled product and return them in the array
 * @return array $cart_details Cart Details Meta Values
 */
function edd_get_payment_meta_cart_details( $payment_id, $include_bundle_files = false ) {
	$payment      = new EDD_Payment( $payment_id );
	$cart_details = $payment->cart_details;

	$payment_currency = $payment->currency;

	if ( ! empty( $cart_details ) && is_array( $cart_details ) ) {

		foreach ( $cart_details as $key => $cart_item ) {
			$cart_details[ $key ]['currency'] = $payment_currency;

			// Ensure subtotal is set, for pre-1.9 orders
			if ( ! isset( $cart_item['subtotal'] ) ) {
				$cart_details[ $key ]['subtotal'] = $cart_item['price'];
			}

			if ( $include_bundle_files ) {

				if( 'bundle' != edd_get_download_type( $cart_item['id'] ) )
					continue;

				$price_id = edd_get_cart_item_price_id( $cart_item );
				$products = edd_get_bundled_products( $cart_item['id'], $price_id );

				if ( empty( $products ) )
					continue;

				foreach ( $products as $product_id ) {
					$cart_details[]   = array(
						'id'          => $product_id,
						'name'        => get_the_title( $product_id ),
						'item_number' => array(
							'id'      => $product_id,
							'options' => array(),
						),
						'price'       => 0,
						'subtotal'    => 0,
						'quantity'    => 1,
						'tax'         => 0,
						'in_bundle'   => 1,
						'parent'      => array(
							'id'      => $cart_item['id'],
							'options' => isset( $cart_item['item_number']['options'] ) ? $cart_item['item_number']['options'] : array()
						)
					);
				}
			}
		}

	}

	return apply_filters( 'edd_payment_meta_cart_details', $cart_details, $payment_id );
}

/**
 * Get the user email associated with a payment
 *
 * @since 1.2
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return string $email User email.
 */
function edd_get_payment_user_email( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return '';
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->get_email()
		: '';
}

/**
 * Check if the order is associated with a user.
 *
 * @since 2.4.4
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return bool True if the payment is **not** associated with a user, false otherwise.
 */
function edd_is_guest_payment( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return false;
	}

	$order   = edd_get_order( $order_id );
	$user_id = $order->get_user_id();

	$is_guest_payment = ! empty( $user_id ) && $user_id > 0
		? false
		: true;

	return (bool) apply_filters( 'edd_is_guest_payment', $is_guest_payment, $order_id );
}

/**
 * Get the user ID associated with an order.
 *
 * @since 1.5.1
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return string $user_id User ID.
 */
function edd_get_payment_user_id( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return 0;
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->get_user_id()
		: 0;
}

/**
 * Get the customer ID associated with an order.
 *
 * @since 2.1
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return int $customer_id Customer ID.
 */
function edd_get_payment_customer_id( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return 0;
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->get_customer_id()
		: 0;
}

/**
 * Get the status of the unlimited downloads flag
 *
 * @since 2.0
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return bool True if the payment has unlimited downloads, false otherwise.
 */
function edd_payment_has_unlimited_downloads( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return false;
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->has_unlimited_downloads()
		: false;
}

/**
 * Get the IP address used to make a purchase.
 *
 * @since 1.9
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return string User's IP address.
 */
function edd_get_payment_user_ip( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return '';
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->get_ip()
		: '';
}

/**
 * Get the date an order was completed.
 *
 * @since 2.0
 * @since 3.0 Parameter renamed to $order_id.
 *
 * @param int $order_id Order ID.
 * @return string The date the order was completed.
 */
function edd_get_payment_completed_date( $order_id = 0 ) {
	$payment = edd_get_payment( $order_id );
	return $payment->completed_date;
}

/**
 * Get the gateway associated with an order.
 *
 * @since 1.2
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return string Payment gateway used for the order.
 */
function edd_get_payment_gateway( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return '';
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->get_gateway()
		: '';
}

/**
 * Get the currency code an order was made in.
 *
 * @since 2.2
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return string $currency The currency code
 */
function edd_get_payment_currency_code( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return '';
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->get_currency()
		: '';
}

/**
 * Get the currency name a payment was made in.
 *
 * @since 2.2
 * @since 3.0 Parameter renamed to $order_id.
 *
 * @param int $order_id Order ID.
 * @return string $currency The currency name.
 */
function edd_get_payment_currency( $order_id = 0 ) {
	$currency = edd_get_payment_currency_code( $order_id );

	/**
	 * Allow the currency to be filtered.
	 *
	 * @since 2.2
	 *
	 * @param string $currency Currency name.
	 * @param int    $order_id Order ID.
	 */
	return apply_filters( 'edd_payment_currency', edd_get_currency_name( $currency ), $order_id );
}

/**
 * Get the payment key for an order.
 *
 * @since 1.2
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return string $key Purchase key.
 */
function edd_get_payment_key( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return '';
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->get_payment_key()
		: '';
}

/**
 * Get the payment order number.
 *
 * This will return the order ID if sequential order numbers are not enabled or the order number does not exist.
 *
 * @since 2.0
 * @since 3.0 Refactored to use EDD\Orders\Order.
 *
 * @param int $order_id Order ID.
 * @return int|string Payment order number.
 */
function edd_get_payment_number( $order_id = 0 ) {

	// Bail if nothing was passed.
	if ( empty( $order_id ) ) {
		return 0;
	}

	$order = edd_get_order( $order_id );

	return $order
		? $order->get_number()
		: 0;
}

/**
 * Formats the order number with the prefix and postfix.
 *
 * @since 2.4
 *
 * @param int $number The order number to format.
 * @return string The formatted order number
 */
function edd_format_payment_number( $number ) {
	if ( ! edd_get_option( 'enable_sequential' ) ) {
		return $number;
	}

	if ( ! is_numeric( $number ) ) {
		return $number;
	}

	$prefix  = edd_get_option( 'sequential_prefix' );
	$number  = absint( $number );
	$postfix = edd_get_option( 'sequential_postfix' );

	$formatted_number = $prefix . $number . $postfix;

	return apply_filters( 'edd_format_payment_number', $formatted_number, $prefix, $number, $postfix );
}

/**
 * Gets the next available order number.
 *
 * This is used when inserting a new order.
 *
 * @since 2.0
 *
 * @return string $number The next available order number.
 */
function edd_get_next_payment_number() {
	if ( ! edd_get_option( 'enable_sequential' ) ) {
		return false;
	}

	$number           = get_option( 'edd_last_payment_number' );
	$start            = edd_get_option( 'sequential_start', 1 );
	$increment_number = true;

	if ( false !== $number ) {
		if ( empty( $number ) ) {
			$number = $start;
			$increment_number = false;
		}
	} else {

		// This case handles the first addition of the new option, as well as if it get's deleted for any reason
		$payments = new EDD_Payments_Query( array(
			'number'  => 1,
			'order'   => 'DESC',
			'orderby' => 'ID',
			'output'  => 'posts',
			'fields'  => 'ids',
		) );

		$last_payment = $payments->get_payments();

		if ( ! empty( $last_payment ) ) {
			$number = edd_get_payment_number( $last_payment[0]->ID );
		}

		if ( ! empty( $number ) && $number !== (int) $last_payment[0]->ID ) {
			$number = edd_remove_payment_prefix_postfix( $number );
		} else {
			$number = $start;
			$increment_number = false;
		}
	}

	$increment_number = apply_filters( 'edd_increment_payment_number', $increment_number, $number );

	if ( $increment_number ) {
		$number++;
	}

	return apply_filters( 'edd_get_next_payment_number', $number );
}

/**
 * Given a given a number, remove the pre/postfix.
 *
 * @since 2.4
 *
 * @param string $number The formatted number to increment.
 * @return string  The new order number without prefix and postfix.
 */
function edd_remove_payment_prefix_postfix( $number ) {
	$prefix  = edd_get_option( 'sequential_prefix' );
	$postfix = edd_get_option( 'sequential_postfix' );

	// Remove prefix
	$number = preg_replace( '/' . $prefix . '/', '', $number, 1 );

	// Remove the postfix
	$length      = strlen( $number );
	$postfix_pos = strrpos( $number, $postfix );
	if ( false !== $postfix_pos ) {
		$number = substr_replace( $number, '', $postfix_pos, $length );
	}

	// Ensure it's a whole number
	$number = intval( $number );

	return apply_filters( 'edd_remove_payment_prefix_postfix', $number, $prefix, $postfix );
}

/**
 * Get the fully formatted payment amount. The payment amount is retrieved using
 * edd_get_payment_amount() and is then sent through edd_currency_filter() and
 * edd_format_amount() to format the amount correctly.
 *
 * @since 1.4
 * @param int $payment_id Payment ID
 * @return string $amount Fully formatted payment amount
 */
function edd_payment_amount( $payment_id = 0 ) {
	$amount = edd_get_payment_amount( $payment_id );
	return edd_currency_filter( edd_format_amount( $amount ), edd_get_payment_currency_code( $payment_id ) );
}

/**
 * Get the amount associated with a payment
 *
 * @since 1.2
 * @param int $payment_id Payment ID
 * @return float Payment amount
 */
function edd_get_payment_amount( $payment_id ) {
	$payment = new EDD_Payment( $payment_id );

	return apply_filters( 'edd_payment_amount', floatval( $payment->total ), $payment_id );
}

/**
 * Retrieves subtotal for payment (this is the amount before taxes) and then
 * returns a full formatted amount. This function essentially calls
 * edd_get_payment_subtotal()
 *
 * @since 1.3.3
 *
 * @param int $payment_id Payment ID
 *
 * @see edd_get_payment_subtotal()
 *
 * @return array Fully formatted payment subtotal
 */
function edd_payment_subtotal( $payment_id = 0 ) {
	$subtotal = edd_get_payment_subtotal( $payment_id );

	return edd_currency_filter( edd_format_amount( $subtotal ), edd_get_payment_currency_code( $payment_id ) );
}

/**
 * Retrieves subtotal for payment (this is the amount before taxes) and then
 * returns a non formatted amount.
 *
 * @since 1.3.3
 * @param int $payment_id Payment ID
 * @return float $subtotal Subtotal for payment (non formatted)
 */
function edd_get_payment_subtotal( $payment_id = 0) {
	$payment = new EDD_Payment( $payment_id );

	return $payment->subtotal;
}

/**
 * Retrieves taxed amount for payment and then returns a full formatted amount
 * This function essentially calls edd_get_payment_tax()
 *
 * @since 1.3.3
 * @see edd_get_payment_tax()
 * @param int $payment_id Payment ID
 * @param bool $payment_meta Payment Meta provided? (default: false)
 * @return string $subtotal Fully formatted payment subtotal
 */
function edd_payment_tax( $payment_id = 0, $payment_meta = false ) {
	$tax = edd_get_payment_tax( $payment_id, $payment_meta );

	return edd_currency_filter( edd_format_amount( $tax ), edd_get_payment_currency_code( $payment_id ) );
}

/**
 * Retrieves taxed amount for payment and then returns a non formatted amount
 *
 * @since 1.3.3
 * @param int $payment_id Payment ID
 * @param bool $payment_meta Get payment meta?
 * @return float $tax Tax for payment (non formatted)
 */
function edd_get_payment_tax( $payment_id = 0, $payment_meta = false ) {
	$payment = new EDD_Payment( $payment_id );

	return $payment->tax;
}

/**
 * Retrieve the tax for a cart item by the cart key
 *
 * @since  2.5
 * @param  integer $payment_id The Payment ID
 * @param  int     $cart_key   The cart key
 * @return float               The item tax amount
 */
function edd_get_payment_item_tax( $payment_id = 0, $cart_key = false ) {
	$payment = new EDD_Payment( $payment_id );
	$item_tax = 0;

	$cart_details = $payment->cart_details;

	if ( false !== $cart_key && ! empty( $cart_details ) && array_key_exists( $cart_key, $cart_details ) ) {
		$item_tax = ! empty( $cart_details[ $cart_key ]['tax'] ) ? $cart_details[ $cart_key ]['tax'] : 0;
	}

	return $item_tax;

}

/**
 * Retrieves arbitrary fees for the payment
 *
 * @since 1.5
 * @param int $payment_id Payment ID
 * @param string $type Fee type
 * @return mixed array if payment fees found, false otherwise
 */
function edd_get_payment_fees( $payment_id = 0, $type = 'all' ) {
	$payment = new EDD_Payment( $payment_id );
	return $payment->get_fees( $type );
}

/**
 * Retrieves the transaction ID for the given payment
 *
 * @since  2.1
 * @param int $payment_id Payment ID
 * @return string The Transaction ID
 */
function edd_get_payment_transaction_id( $payment_id = 0 ) {
	$payment = new EDD_Payment( $payment_id );
	return $payment->transaction_id;
}

/**
 * Sets a Transaction ID in post meta for the given Payment ID
 *
 * @since  2.1
 * @param int $payment_id Payment ID
 * @param string $transaction_id The transaction ID from the gateway
 * @return mixed Meta ID if successful, false if unsuccessful
 */
function edd_set_payment_transaction_id( $payment_id = 0, $transaction_id = '' ) {

	if ( empty( $payment_id ) || empty( $transaction_id ) ) {
		return false;
	}

	$transaction_id = apply_filters( 'edd_set_payment_transaction_id', $transaction_id, $payment_id );

	return edd_update_payment_meta( $payment_id, '_edd_payment_transaction_id', $transaction_id );
}

/**
 * Retrieve the purchase ID based on the purchase key
 *
 * @since 1.3.2
 * @global object $wpdb Used to query the database using the WordPress
 *   Database API
 * @param string $key the purchase key to search for
 * @return int $purchase Purchase ID
 */
function edd_get_purchase_id_by_key( $key ) {
	global $wpdb;
	$global_key_string = 'edd_purchase_id_by_key' . $key;
	global $$global_key_string;

	if ( null !== $$global_key_string ) {
		return $$global_key_string;
	}

	/** @var EDD\Orders\Order $order */
	$order = edd_get_order_by( 'payment_key', $key );

	if ( false !== $order ) {
		$$global_key_string = $order->get_id();
		return $$global_key_string;
	}

	return 0;
}

/**
 * Retrieve the purchase ID based on the transaction ID
 *
 * @since 2.4
 * @global object $wpdb Used to query the database using the WordPress
 *   Database API
 * @param string $key the transaction ID to search for
 * @return int $purchase Purchase ID
 */
function edd_get_purchase_id_by_transaction_id( $key ) {
	global $wpdb;

	$purchase = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_edd_payment_transaction_id' AND meta_value = %s LIMIT 1", $key ) );

	if ( $purchase != NULL )
		return $purchase;

	return 0;
}

/**
 * Retrieve all notes attached to an order.
 *
 * @since 1.4
 * @since 3.0 Updated to use the edd_notes custom table to store notes.
 *
 * @param int    $order_id   The order ID to retrieve notes for.
 * @param string $search     Search for notes that contain a search term.
 * @return array|bool $notes Order notes, false otherwise.
 */
function edd_get_payment_notes( $order_id = 0, $search = '' ) {
	if ( empty( $order_id ) && empty( $search ) ) {
		return false;
	}

	$notes = edd_get_notes( array(
		'object_id'   => $order_id,
		'object_type' => 'order',
		'order'       => 'ASC',
		'search'      => '',
	) );

	return $notes;
}


/**
 * Add a note to an order.
 *
 * @since 1.4
 * @since 3.0 Updated to use the edd_notes custom table to store notes.
 *
 * @param int    $order_id The order ID to store a note for.
 * @param string $note     The content of the note.
 * @return int|false The new note ID, false otherwise.
 */
function edd_insert_payment_note( $order_id = 0, $note = '' ) {

	// Bail if no order ID or note.
	if ( empty( $order_id ) || empty( $note ) ) {
		return false;
	}

	do_action( 'edd_pre_insert_payment_note', $order_id, $note );

	/**
	 * For backwards compatibility purposes, we need to pass the data to
	 * wp_filter_comment in the event that the note data is filtered using the
	 * WordPress Core filters prior to be inserted into the database.
	 */
	$filtered_data = wp_filter_comment( array(
		'comment_post_ID'      => $order_id,
		'comment_content'      => $note,
		'user_id'              => is_admin() ? get_current_user_id() : 0,
		'comment_date'         => current_time( 'mysql' ),
		'comment_date_gmt'     => current_time( 'mysql', 1 ),
		'comment_approved'     => 1,
		'comment_parent'       => 0,
		'comment_author'       => '',
		'comment_author_IP'    => '',
		'comment_author_url'   => '',
		'comment_author_email' => '',
		'comment_type'         => 'edd_payment_note'
	) );

	// Add the note
	$note_id = edd_add_note( array(
		'object_id'   => $filtered_data['comment_post_ID'],
		'content'     => $filtered_data['comment_content'],
		'user_id'     => $filtered_data['user_id'],
		'object_type' => 'order',
	) );

	do_action( 'edd_insert_payment_note', $note_id, $order_id, $note );

	// Return the ID of the new note
	return $note_id;
}

/**
 * Deletes an order note.
 *
 * @since 1.6
 * @since 3.0 Updated to use the edd_notes custom table to store notes.
 *
 * @param int $note_id  Note ID.
 * @param int $order_id Order ID.
 * @return bool True on success, false otherwise.
 */
function edd_delete_payment_note( $note_id = 0, $order_id = 0 ) {
	if ( empty( $note_id ) ) {
		return false;
	}

	do_action( 'edd_pre_delete_payment_note', $note_id, $order_id );

	$ret = edd_delete_note( $note_id );

	do_action( 'edd_post_delete_payment_note', $note_id, $order_id );

	return $ret;
}

/**
 * Gets the payment note HTML.
 *
 * @since 1.9
 * @since 3.0 Deprecated & unused (use edd_admin_get_note_html())
 *
 * @param object|int $note       The note object or ID.
 * @param int        $payment_id The payment ID the note is connected to.
 *
 * @return string Payment note HTML.
 */
function edd_get_payment_note_html( $note, $payment_id = 0 ) {
	return edd_admin_get_note_html( $note );
}

/**
 * Exclude notes (comments) on edd_payment post type from showing in Recent
 * Comments widgets
 *
 * @since 1.4.1
 * @param obj $query WordPress Comment Query Object
 * @return void
 */
function edd_hide_payment_notes( $query ) {
	global $wp_version;

	if ( version_compare( floatval( $wp_version ), '4.1', '>=' ) ) {
		$types = isset( $query->query_vars['type__not_in'] ) ? $query->query_vars['type__not_in'] : array();
		if ( ! is_array( $types ) ) {
			$types = array( $types );
		}
		$types[] = 'edd_payment_note';
		$query->query_vars['type__not_in'] = $types;
	}
}
add_action( 'pre_get_comments', 'edd_hide_payment_notes', 10 );

/**
 * Exclude notes (comments) on edd_payment post type from showing in Recent
 * Comments widgets
 *
 * @since 2.2
 * @param array $clauses Comment clauses for comment query
 * @param obj $wp_comment_query WordPress Comment Query Object
 * @return array $clauses Updated comment clauses
 */
function edd_hide_payment_notes_pre_41( $clauses, $wp_comment_query ) {
	global $wpdb, $wp_version;

	if( version_compare( floatval( $wp_version ), '4.1', '<' ) ) {
		$clauses['where'] .= ' AND comment_type != "edd_payment_note"';
	}

	return $clauses;
}
add_filter( 'comments_clauses', 'edd_hide_payment_notes_pre_41', 10, 2 );


/**
 * Exclude notes (comments) on edd_payment post type from showing in comment feeds
 *
 * @since 1.5.1
 * @param array $where
 * @param obj $wp_comment_query WordPress Comment Query Object
 * @return array $where
 */
function edd_hide_payment_notes_from_feeds( $where, $wp_comment_query ) {
    global $wpdb;

	$where .= $wpdb->prepare( " AND comment_type != %s", 'edd_payment_note' );
	return $where;
}
add_filter( 'comment_feed_where', 'edd_hide_payment_notes_from_feeds', 10, 2 );


/**
 * Remove EDD Comments from the wp_count_comments function
 *
 * @since 1.5.2
 * @param array $stats (empty from core filter)
 * @param int $post_id Post ID
 * @return array Array of comment counts
*/
function edd_remove_payment_notes_in_comment_counts( $stats, $post_id ) {
	global $wpdb, $pagenow;

	$array_excluded_pages = array( 'index.php', 'edit-comments.php' );
	if( ! in_array( $pagenow, $array_excluded_pages )  ) {
		return $stats;
	}

	$post_id = (int) $post_id;

	if ( apply_filters( 'edd_count_payment_notes_in_comments', false ) )
		return $stats;

	$stats = wp_cache_get( "comments-{$post_id}", 'counts' );

	if ( false !== $stats )
		return $stats;

	$where = 'WHERE comment_type != "edd_payment_note"';

	if ( $post_id > 0 )
		$where .= $wpdb->prepare( " AND comment_post_ID = %d", $post_id );

	$count = $wpdb->get_results( "SELECT comment_approved, COUNT( * ) AS num_comments FROM {$wpdb->comments} {$where} GROUP BY comment_approved", ARRAY_A );

	$total = 0;
	$approved = array( '0' => 'moderated', '1' => 'approved', 'spam' => 'spam', 'trash' => 'trash', 'post-trashed' => 'post-trashed' );
	foreach ( (array) $count as $row ) {
		// Don't count post-trashed toward totals
		if ( 'post-trashed' != $row['comment_approved'] && 'trash' != $row['comment_approved'] )
			$total += $row['num_comments'];
		if ( isset( $approved[$row['comment_approved']] ) )
			$stats[$approved[$row['comment_approved']]] = $row['num_comments'];
	}

	$stats['total_comments'] = $total;
	foreach ( $approved as $key ) {
		if ( empty($stats[$key]) )
			$stats[$key] = 0;
	}

	$stats = (object) $stats;
	wp_cache_set( "comments-{$post_id}", $stats, 'counts' );

	return $stats;
}
add_filter( 'wp_count_comments', 'edd_remove_payment_notes_in_comment_counts', 10, 2 );


/**
 * Filter where older than one week
 *
 * @since 1.6
 * @param string $where Where clause
 * @return string $where Modified where clause
*/
function edd_filter_where_older_than_week( $where = '' ) {
	// Payments older than one week
	$start = date( 'Y-m-d', strtotime( '-7 days' ) );
	$where .= " AND post_date <= '{$start}'";
	return $where;
}
