<?php
/**
 * Model class <i>SIB_Model_Contact</i> represents account
 *
 * @package SIB_Model
 */

class SIB_Model_Contact {

	/**
	 * Tab table name
	 */
	const TABLE_NAME = 'sib_model_contact';

	/**
	 * Holds found campaign count
	 */
	static $found_count;

	/**
	 * Holds all campaign count
	 */
	static $all_count;

	/** Create Table */
	public static function create_table() {
		global $wpdb;
		// Create list table.
		$creation_query =
			'CREATE TABLE IF NOT EXISTS ' . $wpdb->prefix . self::TABLE_NAME . ' (
			`id` int(20) NOT NULL AUTO_INCREMENT,
			`email` varchar(255),
            `info` TEXT,
            `code` varchar(100),
            `is_activate` int(2),
			`extra` TEXT,
			PRIMARY KEY (`id`)
			);';
		// phpcs:ignore
		$wpdb->query( $creation_query );
	}

	/**
	 * Remove table
	 */
	public static function remove_table() {
		global $wpdb;
		$query = 'DROP TABLE IF EXISTS ' . $wpdb->prefix . self::TABLE_NAME . ';';
		// phpcs:ignore
		$wpdb->query( $query );
	}

	/**
	 * Get data by id
	 *
	 * @param $id
	 */
	public static function get_data( $id ) {
		global $wpdb;
		// phpcs:ignore
		$query   = $wpdb->prepare( 'select * from ' . $wpdb->prefix . self::TABLE_NAME . ' where id= %d ', array( esc_sql( $id ) ) );
		// phpcs:ignore
		$results = $wpdb->get_results( $query, ARRAY_A );

		if ( is_array( $results ) ) {
			return $results[0];
		} else {
			return false;
		}
	}

	/**
	 * Get data by code
	 */
	public static function get_data_by_code( $code ) {
		global $wpdb;
		// phpcs:ignore
		$query   = $wpdb->prepare( 'select * from ' . $wpdb->prefix . self::TABLE_NAME . ' where code like %s', array( esc_sql( $code ) ) );
		// phpcs:ignore
		$results = $wpdb->get_results( $query, ARRAY_A );

		if ( is_array( $results ) ) {
			return $results[0];
		} else {
			return false;
		}
	}

	/**
	 * Get code by email
	 */
	public static function get_data_by_email( $email ) {
		global $wpdb;
		// phpcs:ignore
		$query   = $wpdb->prepare( 'select * from ' . $wpdb->prefix . self::TABLE_NAME . ' where email like %s', array( esc_sql( $email ) ) );
		// phpcs:ignore
		$results = $wpdb->get_results( $query, ARRAY_A );

		if ( is_array( $results ) && count( $results ) > 0 ) {
			return $results[0];
		} else {
			return false;
		}
	}

	/** Add record */
	public static function add_record( $data ) {
		global $wpdb;

		if ( true === self::is_exist_same_email( $data['email'] ) ) {
			return false;
		}

		// phpcs:ignore
		$query = $wpdb->prepare( 'INSERT INTO ' . $wpdb->prefix . self::TABLE_NAME . ' ' . '(email,info,code,is_activate,extra) VALUES (%s,%s,%s,%d,%s)', array( esc_sql( $data['email'] ), esc_sql( $data['info'] ), esc_sql( $data['code'] ), esc_sql( $data['is_activate'] ), esc_sql( $data['extra'] ) ) );

		// phpcs:ignore
		$wpdb->query( $query );

		$index = $wpdb->get_var( 'SELECT LAST_INSERT_ID();' );

		return $index;

	}

	public static function is_exist_same_email( $email, $id = '' ) {
		global $wpdb;

		// phpcs:ignore
		$query = $wpdb->prepare( 'select * from ' . $wpdb->prefix . self::TABLE_NAME . ' where email like %s ', array( esc_sql( $email ) ) );

		// phpcs:ignore
		$results = $wpdb->get_results( $query, ARRAY_A );

		if ( is_array( $results ) && ( count( $results ) > 0 ) ) {
			if ( '' === $id ) {
				return true;
			}
			if ( isset( $results ) && is_array( $results ) ) {
				foreach ( $results as $result ) {
					if ( $result['id'] !== $id ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/** Remove guest */
	public static function remove_record( $id ) {
		global $wpdb;

		// phpcs:ignore
		$query = $wpdb->prepare( 'delete from ' . $wpdb->prefix . self::TABLE_NAME . ' ' . ' where id= %d ', array( esc_sql( $id ) ) );

		// phpcs:ignore
		$wpdb->query( $query );
	}

	/** Get all guests by pagenum, per_page*/
	public static function get_all( $orderby = 'email', $order = 'asc', $pagenum = 1, $per_page = 15 ) {
		global $wpdb;
		$limit = ( $pagenum - 1 ) * $per_page;
		// phpcs:ignore
		$query = $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE_NAME . ' ORDER BY %s %s LIMIT %d , %d', array( esc_sql( $orderby ), esc_sql( $order ), esc_sql( $limit ), esc_sql( $per_page ) ) );

		// phpcs:ignore
		$results           = $wpdb->get_results( $query, ARRAY_A );
		self::$found_count = self::get_count_element();

		if ( ! is_array( $results ) ) {
			$results = array();
			return $results;
		}

		return $results;
	}

	/** Get all records of table */
	public static function get_all_records() {
		global $wpdb;

		$query = 'select * from ' . $wpdb->prefix . self::TABLE_NAME . ' order by email asc';

		// phpcs:ignore
		$results = $wpdb->get_results( $wpdb->prepare( $query ), ARRAY_A );

		if ( ! is_array( $results ) ) {
			$results = array();
			return $results;
		}

		return $results;
	}

	/** Get count of row */
	public static function get_count_element() {
		global $wpdb;

		$query = 'Select count(*) from ' . $wpdb->prefix . self::TABLE_NAME . ';';

		// phpcs:ignore
		$count = $wpdb->get_var( $wpdb->prepare( $query ) );

		return $count;
	}

	/** Update record */
	public static function update_element( $id, $data ) {
		global $wpdb;

		if ( self::is_exist_same_email( $data['email'], $id ) === true ) {
			return false;
		}

		// phpcs:ignore
		$query = $wpdb->prepare( 'update ' . $wpdb->prefix . self::TABLE_NAME . ' ' . "set email='{%s}',info='{%s}',code='{%s}',is_activate='{%d}',extra='{%s}' where id= %d", array( esc_sql( $data['email'] ), esc_sql( $data['info'] ), esc_sql( $data['code'] ), esc_sql( $data['is_activate'] ), esc_sql( $data['extra'] ), esc_sql( $id ) ) );

		// phpcs:ignore
		$wpdb->query( $query );

		return true;
	}

	/** Add prefix to the table */
	public static function add_prefix() {
		global $wpdb;
		// phpcs:ignore
		if ( $wpdb->get_var( "SHOW TABLES LIKE '" . self::TABLE_NAME . "'" ) === self::TABLE_NAME ) {
				$query = 'ALTER TABLE ' . self::TABLE_NAME . ' RENAME TO ' . $wpdb->prefix . self::TABLE_NAME . ';';
				// phpcs:ignore
				$wpdb->query( $query ); // db call ok; no-cache ok.
		}
	}

}
