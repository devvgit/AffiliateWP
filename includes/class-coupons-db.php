<?php
/**
 * Core class that implements a database layer for coupons.
 *
 * @since 2.1
 *
 * @see \Affiliate_WP_DB
 *
 * @property-read \AffWP\Affiliate\Coupon\REST\v1\Endpoints $REST Affiliates REST endpoints.
 */
class Affiliate_WP_Coupons_DB extends Affiliate_WP_DB {

	/**
	 * Cache group for queries.
	 *
	 * @internal DO NOT change. This is used externally both as a cache group and shortcut
	 *           for accessing db class instances via affiliate_wp()->{$cache_group}->*.
	 *
	 * @access public
	 * @since  2.1
	 * @var    string
	 */
	public $cache_group = 'coupons';

	/**
	 * Object type to query for.
	 *
	 * @access public
	 * @since  2.1
	 * @var    string
	 */
	public $query_object_type = 'AffWP\Affiliate\Coupon';

	/**
	 * Constructor.
	 *
	 * @access public
	 * @since  2.1
	*/
	public function __construct() {
		global $wpdb, $wp_version;

		if( defined( 'AFFILIATE_WP_NETWORK_WIDE' ) && AFFILIATE_WP_NETWORK_WIDE ) {
			// Allows a single coupons table for the whole network.
			$this->table_name  = 'affiliate_wp_coupons';
		} else {
			$this->table_name  = $wpdb->prefix . 'affiliate_wp_coupons';
		}
		$this->primary_key = 'coupon_id';
		$this->version     = '1.0';

		// REST endpoints.
		if ( version_compare( $wp_version, '4.4', '>=' ) ) {
			$this->REST = new \AffWP\Affiliate\Coupon\REST\v1\Endpoints;
		}
	}

	/**
	 * Retrieves a coupon object.
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @see Affiliate_WP_DB::get_core_object()
	 *
	 * @param int $coupon Coupon ID or object.
	 * @return AffWP\Affiliate\Coupon|false Coupon object, null otherwise.
	 */
	public function get_object( $coupon ) {
		return $this->get_core_object( $coupon, $this->query_object_type );
	}

	/**
	 * Retrieves table columns and date types.
	 *
	 * @access public
	 * @since  2.1
	*/
	public function get_columns() {
		return array(
			'integration_coupon_id' => '%d',
			'coupon_code'           => '%d',
			'coupon_id'       => '%d',
			'affiliate_id'          => '%d',
			'referrals'             => '%s',
			'integration'           => '%s',
			'owner'                 => '%d',
			'status'                => '%s',
			'expiration_date'       => '%s'
		);
	}

	/**
	 * Retrieves default column values.
	 *
	 * @access public
	 * @since  2.1
	 */
	public function get_column_defaults() {
		return array(
			'integration_coupon_id' => 0,
			'coupon_id'       => 0,
			'owner'                 => 0,
			'status'                => 'active',
			'expiration_date'       => date( 'Y-m-d H:i:s' )
		);
	}

	/**
	 * Adds a new single coupon.
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @param array $args {
	 *     Optional. Array of arguments for adding a new coupon. Default empty array.
	 *
	 *     @type int        $affiliate_id    Affiliate ID the coupon should be associated with.
	 *     @type int        $integration_coupon_id       The coupon ID generated by the integration.
	 *     @type array      $referrals       Referral ID or array of IDs to associate the coupon with.
	 *     @type float      $amount          Coupon amount.
	 *     @type int        $owner           ID of the user who generated the coupon. Default is the ID
	 *                                       of the current user.
	 *     @type string     $status          Coupon status. Will be 'paid' unless there's a problem.
	 *     @type int|string $expiration_date Date string or timestamp for when the coupon expires.
	 * }
	 * @return int|false Coupon ID if successfully added, otherwise false.
	 */
	public function add( $args = array() ) {

		$args = wp_parse_args( $args, array(
			'affiliate_id'          => 0,
			'coupon_code'           => 0,
			'integration_coupon_id' => 0,
			'coupon_id'             => 0,
			'referrals'             => array(),
			'integration'           => '',
			'owner'                 => get_current_user_id(),
			'status'                => 'active',
		) );

		$args['affiliate_id'] = absint( $args['affiliate_id'] );

		if ( ! affiliate_wp()->affiliates->affiliate_exists( $args['affiliate_id'] ) ) {
			return false;
		}

		if ( ! empty( $args['status'] ) ) {
			$args['status'] = sanitize_key( $args['status'] );
		}

		if ( ! empty( $args['coupon_code'] ) ) {
			$args['coupon_code'] = sanitize_key( $args['coupon_code'] );
		}

		if ( is_array( $args['referrals'] ) ) {
			$args['referrals'] = array_map( 'absint', $args['referrals'] );
		} else {
			$args['referrals'] = (array) absint( $args['referrals'] );
		}

		$referrals = array();

		foreach ( $args['referrals'] as $referral_id ) {
			if ( $referral = affwp_get_referral( $referral_id ) ) {
				// Only keep it if the referral is real and the affiliate IDs match.
				if ( $args['affiliate_id'] === $referral->affiliate_id ) {
					$referrals[] = $referral_id;
				}
			}
		}

		if ( ! empty( $args['integration'] ) ) {
			$args['integration'] = $integration;
		}


		if ( empty( $referrals ) ) {
			$add = false;
		} else {
			$args['referrals'] = implode( ',', wp_list_pluck( $referrals, 'referral_id' ) );

			$add = $this->insert( $args, 'coupon' );
		}

		if ( $add ) {
			/**
			 * Fires immediately after a coupon has been successfully inserted.
			 *
			 * @since 2.1
			 *
			 * @param int $add New coupon ID.
			 */
			do_action( 'affwp_insert_coupon', $add );

			return $add;
		}

		return false;
	}

	/**
	 * Builds an associative array of affiliate IDs to their corresponding referrals.
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @param array  $referrals Array of referral IDs.
	 * @param string $status    Optional. Required referral status. Pass an empty string to disable.
	 *                          Default 'paid'.
	 * @return array Associative array of affiliates to referral IDs where affiliate IDs
	 *               are the index with a sub-array of corresponding referral IDs. Referrals
	 *               with a status other than 'paid' will be skipped.
	 */
	public function get_affiliate_ids_by_referrals( $referrals, $status = 'paid' ) {
		$referrals = array_map( 'affwp_get_referral', $referrals );

		$affiliates = array();

		foreach ( $referrals as $referral ) {
			if ( ! $referral || ( ! empty( $status ) && $status !== $referral->status ) ) {
				continue;
			}

			$affiliates[ $referral->affiliate_id ][] = $referral->ID;
		}

		return $affiliates;
	}

	/**
	 * Builds an array of coupon IDs given an associative array of affiliate IDS to their
	 * corresponding referral IDs.
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @param array $affiliates Associative array of affiliate IDs to their corresponding
	 *                          referral IDs.
	 * @return array List of coupon IDs for all referrals.
	 */
	public function get_coupon_ids_by_affiliate( $affiliate ) {
		$integration_coupon_ids = array();

		if ( ! empty( $affiliate ) ) {
			$integration_coupon_ids[] = (int) affiliate_wp()->referrals->get_column( 'integration_coupon_id', $referral );
		}

		return array_unique( $integration_coupon_ids );
	}

	/**
	 * Retrieve coupons from the database
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @param array $args {
	 *     Optional. Arguments for querying affiliates. Default empty array.
	 *
	 *     @type int          $number         Number of coupons to query for. Default 20.
	 *     @type int          $offset         Number of coupons to offset the query for. Default 0.
	 *     @type int|array    $integration_coupon_id      Coupon ID or array of coupon IDs to explicitly retrieve. Default 0.
	 *     @type int|array    $affiliate_id   Affiliate ID or array of affiliate IDs to retrieve coupons for. Default 0.
	 *     @type int|array    $referrals      Referral ID or array of referral IDs to retrieve coupons for.
	 *                                        Default empty array.
	 *     @type float|array  $amount {
	 *         Coupon amount to retrieve coupons for or min/max range to retrieve coupons for.
	 *         Default 0.
	 *
	 *         @type float $min Minimum coupon amount.
	 *         @type float $max Maximum coupon amount. Use -1 for no limit.
	 *     }
	 *     @type string       $amount_compare Comparison operator to use in coordination with with $amount when passed
	 *                                        as a float or string. Accepts '>', '<', '>=', '<=', '=', or '!='.
	 *                                        Default '='.
	 *     @type string       $coupon_method  Coupon method to retrieve coupons for. Default empty (all).
	 *     @type string|array $date {
	 *         Date string or start/end range to retrieve coupons for.
	 *
	 *         @type string $start Start date to retrieve coupons for.
	 *         @type string $end   End date to retrieve coupons for.
	 *     }
	 *     @type int|array    $owner          ID or array of IDs for users who generated coupons. Default empty.
	 *     @type string       $status         Coupon status. Default is 'paid' unless there's a problem.
	 *     @type string       $order          How to order returned coupon results. Accepts 'ASC' or 'DESC'.
	 *                                        Default 'DESC'.
	 *     @type string       $orderby        Coupons table column to order results by. Accepts any AffWP\Affiliate\Coupon
	 *                                        field. Default 'integration_coupon_id'.
	 *     @type string       $fields         Fields to limit the selection for. Accepts 'ids'. Default '*' for all.
	 * }
	 * @param bool  $count Optional. Whether to return only the total number of results found. Default false.
	 * @return array|int Array of coupon objects (if found), or integer if `$count` is true.
	 */
	public function get_coupons( $args = array(), $count = false ) {
		global $wpdb;

		$defaults = array(
			'number'                => 20,
			'offset'                => 0,
			'coupon_id'       => 0,
			'integration_coupon_id' => 0,
			'coupon_code'           => 0,
			'affiliate_id'          => 0,
			'referrals'             => 0,
			'integration'           => '',
			'owner'                 => '',
			'status'                => '',
			'expiration_date'       => '',
			'order'                 => 'DESC',
			'orderby'               => 'coupon_id',
			'fields'                => '',
			'search'                => false,
		);

		$args = wp_parse_args( $args, $defaults );

		if( $args['number'] < 1 ) {
			$args['number'] = 999999999999;
		}

		$where = $join = '';

		// Specific coupons.
		if( ! empty( $args['coupon_id'] ) ) {

			$where .= empty( $where ) ? "WHERE " : "AND ";

			if( is_array( $args['coupon_id'] ) ) {
				$coupon_ids = implode( ',', array_map( 'intval', $args['coupon_id'] ) );
			} else {
				$coupon_ids = intval( $args['coupon_id'] );
			}

			$coupon_ids = esc_sql( $coupon_ids );

			$where .= "`coupon_id` IN( {$coupon_ids} ) ";

			unset( $coupon_ids );
		}

		// Affiliate.
		if ( ! empty( $args['affiliate_id'] ) ) {

			$where .= empty( $where ) ? "WHERE " : "AND ";

			if ( is_array( $args['affiliate_id'] ) ) {
				$affiliates = implode( ',', array_map( 'intval', $args['affiliate_id'] ) );
			} else {
				$affiliates = intval( $args['affiliate_id'] );
			}

			$affiliates = esc_sql( $affiliates );

			$where .= "`affiliate_id` IN( {$affiliates} ) ";
		}

		// Coupon integration.
		if ( ! empty( $args['integration'] ) ) {

			$where .= empty( $where ) ? "WHERE " : "AND ";

			$integration = esc_sql( $args['integration'] );

			$where .= "`integration` = '" . $integration . "' ";
		}

		// Owners.
		if ( ! empty( $args['owner'] ) ) {
			$where .= empty( $where ) ? "WHERE " : "AND ";

			if ( is_array( $args['owner'] ) ) {
				$owners = implode( ',', array_map( 'intval', $args['owner'] ) );
			} else {
				$owners = intval( $args['owner'] );
			}

			$owners = esc_sql( $owners );

			$where .= "`owner` IN( {$owners} ) ";
		}

		// Status.
		if ( ! empty( $args['status'] ) ) {

			$where .= empty( $where ) ? "WHERE " : "AND ";

			if ( ! in_array( $args['status'], array( 'active', 'inactive' ), true ) ) {
				$args['status'] = 'active';
			}

			$status = esc_sql( $args['status'] );

			$where .= "`status` = '" . $status . "' ";
		}

		$orderby = array_key_exists( $args['orderby'], $this->get_columns() ) ? $args['orderby'] : $this->primary_key;

		// There can be only two orders.
		if ( 'DESC' === strtoupper( $args['order'] ) ) {
			$order = 'DESC';
		} else {
			$order = 'ASC';
		}

		// Overload args values for the benefit of the cache.
		$args['orderby'] = $orderby;
		$args['order']   = $order;

		// Fields.
		$callback = '';

		if ( 'ids' === $args['fields'] ) {
			$fields   = "$this->primary_key";
			$callback = 'intval';
		} else {
			$fields = $this->parse_fields( $args['fields'] );

			if ( '*' === $fields ) {
				$callback = 'affwp_get_coupon';
			}
		}

		$key = ( true === $count ) ? md5( 'affwp_coupons_count' . serialize( $args ) ) : md5( 'affwp_coupons_' . serialize( $args ) );

		$last_changed = wp_cache_get( 'last_changed', $this->cache_group );
		if ( ! $last_changed ) {
			$last_changed = microtime();
			wp_cache_set( 'last_changed', $last_changed, $this->cache_group );
		}

		$cache_key = "{$key}:{$last_changed}";

		$results = wp_cache_get( $cache_key, $this->cache_group );

		if ( false === $results ) {

			$clauses = compact( 'fields', 'join', 'where', 'orderby', 'order', 'count' );

			$results = $this->get_results( $clauses, $args, $callback );
		}

		wp_cache_add( $cache_key, $results, $this->cache_group, HOUR_IN_SECONDS );

		return $results;

	}

	/**
	 * Retrieves the number of results found for a given query.
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @see Affiliate_WP_Coupons_DB::get_coupons()
	 *
	 * @param array $args Arguments to pass to get_coupons().
	 * @return int Number of coupons.
	 */
	public function count( $args = array() ) {
		return $this->get_coupons( $args, true );
	}

	/**
	 * Checks if a coupon exists.
	 *
	 * @access public
	 * @since  2.1
	*/
	public function coupon_exists( $coupon_id = 0 ) {

		global $wpdb;

		if ( empty( $coupon_id ) ) {
			return false;
		}

		$coupon = $wpdb->query( $wpdb->prepare( "SELECT 1 FROM {$this->table_name} WHERE {$this->primary_key} = %d;", $coupon_id ) );

		return ! empty( $coupon );
	}

	/**
	 * Retrieves the edit URL for the given integration coupon ID.
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @param int    $integration_coupon_id Integration coupon ID to retrieve the edit URL for.
	 * @param string $integration           The integration.
	 * @return string The edit screen url of the coupon template if set, otherwise an empty string.
	 */
	public function get_coupon_edit_url( $integration_coupon_id, $integration ) {

		$integration_coupon_id = $this->get_coupon_template_id( $integration );

		$url = '';

		switch ( $integration ) {
			case 'edd':
				$url = admin_url( 'edit.php?post_type=download&page=edd-discounts&edd-action=edit_discount&discount=' ) . $integration_coupon_id;
				break;

			default : break;
		}

		/**
		 * Filters the coupon template URL for the given integration.
		 *
		 * @since 2.1
		 *
		 * @param string $url         The coupon template URL if one exists, otherwise an empty string.
		 * @param string $integration Integration.
		 */
		return apply_filters( 'affwp_coupon_edit_url', $url, $integration );
	}

	/**
	 * Gets the coupon template used as a basis for generating all automatic affiliate coupons.
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @param string $integration The integration to retrieve the template ID for.
	 * @return int A coupon ID if a coupon template is located for the specified integration, otherwise 0.
	 */
	public function get_coupon_template_id( $integration ) {

		$template_id = 0;

		switch ( $integration ) {
			case 'edd':

					$args = array(
				        'post_type'      => 'edd_discount',
				        'meta_key'       => 'affwp_is_coupon_template',
				        'meta_value'     => 1,
				        'orderby'        => 'meta_value_num',
				        'fields'         => 'ids',
				        'posts_per_page' => 1,
				    );

					$query = new \WP_Query;
					$discount = $query->query( $args );

				    if ( ! empty( $discount[0] ) ) {
				        $template_id = absint( $discount[0] );
				    }

				break;

			default:
				affiliate_wp()->utils->log( sprintf( 'get_coupon_template_id: Unable to determine discount ID from %s integration.', $integration ) );
				break;
		}

		/**
		 * Filters the coupon template ID.
		 *
		 * @since 2.1
		 *
		 * @param int    $template_id The coupon template ID if set, otherwise 0.
		 * @param string $integration The integration to query. Required.
		 */
		return apply_filters( 'affwp_get_coupon_template_id', $template_id, $integration );
	}

	/**
	 * Retrieves an array of referral IDs stored for the coupon.
	 *
	 * @access public
	 * @since  2.1
	 *
	 * @param  AffWP\Affiliate\Coupon|int $coupon        Coupon object or ID.
	 * @return array List of referral IDs if available, otherwise an empty array.
	 */
	public function get_referral_ids( $coupon ) {
		if ( ! $coupon = affwp_get_coupon( $coupon ) ) {
			$referral_ids = array();
		} else {
			$referral_ids = array_map( 'intval', explode( ',', $coupon->referrals ) );
		}

		return $referral_ids;
	}

	/**
	 * Creates the table.
	 *
	 * @access public
	 * @since  2.1
	*/
	public function create_table() {
		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		$sql = "CREATE TABLE " . $this->table_name . " (
			coupon_id bigint(20) NOT NULL AUTO_INCREMENT,
			integration_coupon_id bigint(20) NOT NULL,
			affiliate_id bigint(20) NOT NULL,
			referrals mediumtext NOT NULL,
			integration mediumtext NOT NULL,
			owner bigint(20) NOT NULL,
			status tinytext NOT NULL,
			coupon_code tinytext NOT NULL,
			expiration_date datetime NOT NULL,
			PRIMARY KEY  (coupon_id),
			KEY coupon_id (coupon_id)
			) CHARACTER SET utf8 COLLATE utf8_general_ci;";

		dbDelta( $sql );

		update_option( $this->table_name . '_db_version', $this->version );
	}

}
