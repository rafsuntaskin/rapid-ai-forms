<?php
/**
 * Submission repository.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Forms;

use Rapid_Ai_Forms\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Custom table — direct $wpdb access is intentional. Inserts run on
 * untrusted public submissions where caching would not apply; reads
 * are admin-side and need fresh data.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class Submission_Repository {

	public function create( $form_id, array $data, array $meta = array() ) {
		global $wpdb;

		$row = array(
			'form_id'    => (int) $form_id,
			'data'       => wp_json_encode( $data ),
			'meta'       => wp_json_encode( $meta ),
			'ip_address' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
			'user_id'    => get_current_user_id(),
			'created_at' => current_time( 'mysql', true ),
		);

		$wpdb->insert( Schema::submissions_table(), $row );
		return (int) $wpdb->insert_id;
	}

	public function list_for_form( $form_id, array $args = array() ) {
		global $wpdb;
		$limit  = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$offset = isset( $args['page'] ) ? max( 0, ( (int) $args['page'] - 1 ) * $limit ) : 0;
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE form_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d', Schema::submissions_table(), (int) $form_id, $limit, $offset ), ARRAY_A );
		foreach ( $rows ?: array() as &$r ) {
			$r['data'] = json_decode( $r['data'], true ) ?: array();
			$r['meta'] = json_decode( $r['meta'], true ) ?: array();
		}
		return $rows ?: array();
	}
}
