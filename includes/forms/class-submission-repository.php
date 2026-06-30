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
	 * Hard ceiling on a single export, filterable. Guards against an
	 * unbounded query exhausting memory on very large sites.
	 */
	const EXPORT_MAX_ROWS = 50000;

	/**
	 * Build the shared WHERE fragment + bound params for a filtered query.
	 * `date_from` / `date_to` are `Y-m-d` (server timezone); `created_at` is
	 * stored UTC, so we convert the day bounds to UTC before comparing.
	 *
	 * @param array $args { form_id?, date_from?, date_to? }
	 * @return array { 0: string sql (may be ''), 1: array params }
	 */
	private function where( array $args ) {
		$conditions = array();
		$params     = array();

		$form_id = isset( $args['form_id'] ) ? (int) $args['form_id'] : 0;
		if ( $form_id ) {
			$conditions[] = 's.form_id = %d';
			$params[]     = $form_id;
		}

		// Inclusive day range. from → 00:00:00, to → next day 00:00:00 (so the
		// whole "to" day is covered regardless of time-of-day).
		$from = $this->day_bound_utc( $args['date_from'] ?? '', false );
		if ( $from ) {
			$conditions[] = 's.created_at >= %s';
			$params[]     = $from;
		}
		$to = $this->day_bound_utc( $args['date_to'] ?? '', true );
		if ( $to ) {
			$conditions[] = 's.created_at < %s';
			$params[]     = $to;
		}

		$sql = $conditions ? ( ' WHERE ' . implode( ' AND ', $conditions ) ) : '';
		return array( $sql, $params );
	}

	/**
	 * Normalize a `Y-m-d` filter value to a UTC `Y-m-d H:i:s` boundary, or ''
	 * when blank/invalid. `$end` shifts to the start of the following day.
	 */
	private function day_bound_utc( $date, $end ) {
		$date = is_string( $date ) ? trim( $date ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return '';
		}
		try {
			$dt = new \DateTime( $date . ' 00:00:00', wp_timezone() );
		} catch ( \Exception $e ) {
			return '';
		}
		if ( $end ) {
			$dt->modify( '+1 day' );
		}
		$dt->setTimezone( new \DateTimeZone( 'UTC' ) );
		return $dt->format( 'Y-m-d H:i:s' );
	}

	/**
	 * List submissions across all forms, newest first, optionally filtered
	 * to one form and/or a date range. Each row carries `form_title` from a
	 * join so the admin list can label rows without extra lookups.
	 *
	 * @param array $args { form_id?, page?, per_page?, date_from?, date_to? }
	 */
	public function list( array $args = array() ) {
		global $wpdb;
		$limit  = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$offset = isset( $args['page'] ) ? max( 0, ( (int) $args['page'] - 1 ) * $limit ) : 0;

		list( $where, $params ) = $this->where( $args );

		// %i (table) + %d/%s (filters) + LIMIT/OFFSET, all parameterized.
		$sql    = 'SELECT s.*, f.title AS form_title FROM %i s LEFT JOIN %i f ON f.id = s.form_id'
			. $where . ' ORDER BY s.created_at DESC, s.id DESC LIMIT %d OFFSET %d';
		$args_p = array_merge( array( Schema::submissions_table(), Schema::forms_table() ), $params, array( $limit, $offset ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args_p ), ARRAY_A );
		return $this->decode_rows( $rows );
	}

	public function count( $args = 0 ) {
		global $wpdb;
		// Back-compat: callers historically passed a bare form_id int.
		if ( ! is_array( $args ) ) {
			$args = $args ? array( 'form_id' => (int) $args ) : array();
		}
		list( $where, $params ) = $this->where( $args );
		$sql    = 'SELECT COUNT(*) FROM %i s' . $where;
		$args_p = array_merge( array( Schema::submissions_table() ), $params );
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $args_p ) );
	}

	/**
	 * All submissions matching the filter (no pagination), newest first, for
	 * export. Capped at EXPORT_MAX_ROWS (filterable) to bound memory.
	 *
	 * @param array $args { form_id?, date_from?, date_to? }
	 */
	public function export( array $args = array() ) {
		global $wpdb;
		$max = (int) apply_filters( 'rapid_ai_forms_export_max_rows', self::EXPORT_MAX_ROWS );
		$max = max( 1, $max );

		list( $where, $params ) = $this->where( $args );
		$sql    = 'SELECT s.*, f.title AS form_title FROM %i s LEFT JOIN %i f ON f.id = s.form_id'
			. $where . ' ORDER BY s.created_at DESC, s.id DESC LIMIT %d';
		$args_p = array_merge( array( Schema::submissions_table(), Schema::forms_table() ), $params, array( $max ) );

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args_p ), ARRAY_A );
		return $this->decode_rows( $rows );
	}

	/** Decode the JSON `data`/`meta` columns in place. */
	private function decode_rows( $rows ) {
		$rows = $rows ?: array();
		foreach ( $rows as &$r ) {
			$r['data'] = json_decode( $r['data'], true ) ?: array();
			$r['meta'] = json_decode( $r['meta'], true ) ?: array();
		}
		return $rows;
	}
}
