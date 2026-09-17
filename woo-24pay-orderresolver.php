<?php

defined( 'ABSPATH' ) or exit;

class Order_Number_Resolver
{
    /**
     * List of all known plugins and their meta keys.
     * Extendable via filter.
     *
     * @return array
     */
    private static function get_meta_keys()
    {
        $meta_keys = [
            '_alg_wc_custom_order_number',  // Alg Custom Order Numbers
            '_order_number',                 // WC Sequential Order Numbers (free + pro)
            '_ywson_order_number',           // YITH Sequential Order Numbers
            '_wcj_order_number',             // WooCommerce Jetpack
            '_wc_order_number',              // generic
            '_order_number_formatted',       // formatted order number
        ];

        return apply_filters( '24pay_order_number_meta_keys', $meta_keys );
    }

    /**
     * Main method — returns the internal WC order ID for any custom order number.
     *
     * Includes a short retry/backoff loop so that asynchronous notifications
     * which might arrive before the order is fully committed/visible in the
     * database (e.g. server-to-server webhooks racing with order creation)
     * get a chance to resolve successfully instead of failing immediately.
     * For synchronous calls the order is found on the first attempt, so the
     * retry loop never triggers and adds no overhead.
     *
     * @param mixed $order_number
     * @param int   $retries   number of extra attempts after the first one
     * @param int   $delay_ms  delay between attempts, in milliseconds
     * @return int|false
     */
    public static function resolve( $order_number, $retries = 3, $delay_ms = 300 )
    {
        if ( empty( $order_number ) ) return false;

        $cache_key = 'order_resolve_' . md5( (string) $order_number );
        $cached    = wp_cache_get( $cache_key, '24pay' );
        if ( $cached !== false ) return (int) $cached;

        for ( $attempt = 0; $attempt <= $retries; $attempt++ ) {

            $order_id = self::try_plugins( $order_number );
            if ( ! $order_id ) $order_id = self::try_direct( $order_number );
            if ( ! $order_id ) $order_id = self::try_meta_keys( $order_number );

            if ( $order_id ) {
                wp_cache_set( $cache_key, $order_id, '24pay', 300 );
                return (int) $order_id;
            }

            if ( $attempt < $retries ) {
                usleep( $delay_ms * 1000 );
            }
        }

        return false;
    }

    /**
     * 1. Try native functions of active plugins — fastest approach.
     *
     * @param mixed $order_number
     * @return int|false
     */
    private static function try_plugins( $order_number )
    {
        // YITH
        if ( function_exists( 'ywson_get_order_id_by_order_number' ) ) {
            $id = ywson_get_order_id_by_order_number( $order_number );
            if ( $id ) return (int) $id;
        }

        // WC Sequential free
        if ( function_exists( 'wc_sequential_order_numbers' ) ) {
            $id = wc_sequential_order_numbers()->find_order_by_order_number( $order_number );
            if ( $id ) return (int) $id;
        }

        // WC Sequential pro
        if ( function_exists( 'wc_seq_order_number_pro' ) ) {
            $id = wc_seq_order_number_pro()->find_order_by_order_number( $order_number );
            if ( $id ) return (int) $id;
        }

        // Alg Custom Order Numbers v1.x (class-based API)
        if ( class_exists( 'Alg_WC_Custom_Order_Numbers_Core' ) ) {
            $customOrder = new Alg_WC_Custom_Order_Numbers_Core();
            $id = $customOrder->add_order_number_to_tracking( $order_number );
            if ( $id ) return (int) $id;
        }

        // Alg Custom Order Numbers v2.x (filter-based API)
        if ( function_exists( 'alg_wc_custom_order_numbers' ) ) {
            $id = apply_filters( 'alg_wc_custom_order_numbers_get_order_id_by_order_number', false, $order_number );
            if ( $id ) return (int) $id;
        }

        return false;
    }

    /**
     * 2. Fallback — search by meta keys in the DB.
     *    Works even if the plugin was deactivated but data remains.
     *
     * @param mixed $order_number
     * @return int|false
     */
    private static function try_meta_keys( $order_number )
    {
        foreach ( self::get_meta_keys() as $meta_key ) {
            $orders = wc_get_orders( [
                'meta_key'   => $meta_key,
                'meta_value' => (string) $order_number,
                'limit'      => 1,
                'return'     => 'ids',
            ] );

            if ( ! empty( $orders ) ) return (int) $orders[0];
        }

        return false;
    }

    /**
     * 3. Last resort fallback — the number might be a direct WC order ID.
     *
     * @param mixed $order_number
     * @return int|false
     */
    private static function try_direct( $order_number )
    {
        $order = wc_get_order( (int) $order_number );
        return $order ? (int) $order->get_id() : false;
    }
}

