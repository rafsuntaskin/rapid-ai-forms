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

	/**
	 * List submissions across all forms, newest first, optionally filtered
	 * to one form. Each row carries `form_title` from a join so the admin
	 * list can label rows without extra lookups.
	 *
	 * @param array $args { form_id?, page?, per_page? }
	 */
	public function list( array $args = array() ) {
		global $wpdb;
		$limit   = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$offset  = isset( $args['page'] ) ? max( 0, ( (int) $args['page'] - 1 ) * $limit ) : 0;
		$form_id = isset( $args['form_id'] ) ? (int) $args['form_id'] : 0;

		if ( $form_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.*, f.title AS form_title FROM %i s LEFT JOIN %i f ON f.id = s.form_id WHERE s.form_id = %d ORDER BY s.created_at DESC, s.id DESC LIMIT %d OFFSET %d', Schema::submissions_table(), Schema::forms_table(), $form_id, $limit, $offset ), ARRAY_A );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT s.*, f.title AS form_title FROM %i s LEFT JOIN %i f ON f.id = s.form_id ORDER BY s.created_at DESC, s.id DESC LIMIT %d OFFSET %d', Schema::submissions_table(), Schema::forms_table(), $limit, $offset ), ARRAY_A );
		}

		$rows = $rows ?: array();
		foreach ( $rows as &$r ) {
			$r['data'] = json_decode( $r['data'], true ) ?: array();
			$r['meta'] = json_decode( $r['meta'], true ) ?: array();
		}
		return $rows;
	}

	public function count( $form_id = 0 ) {
		global $wpdb;
		if ( $form_id ) {
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE form_id = %d', Schema::submissions_table(), (int) $form_id ) );
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::submissions_table() ) );
	}
}
