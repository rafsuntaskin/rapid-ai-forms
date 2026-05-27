<?php
/**
 * Form CRUD repository.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Forms;

use Rapid_Ai_Forms\Ai\Schema_Prompt;
use Rapid_Ai_Forms\Db\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * All queries below run against custom plugin tables (registered in
 * Schema::install). The schema editor is admin-only and CRUD always
 * round-trips to the DB, so direct $wpdb calls without an object-cache
 * layer are intentional. Every SELECT uses $wpdb->prepare() with
 * placeholders, including %i for identifiers.
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 */
class Form_Repository {

	public function create( array $data ) {
		global $wpdb;

		$schema = Schema_Prompt::sanitize_schema( isset( $data['schema'] ) && is_array( $data['schema'] ) ? $data['schema'] : array() );

		// Seed the notification To with the site admin email at creation time
		// so editors see a sensible default instead of an empty field. Only
		// applied on create — clearing the field on a later edit is honored.
		if ( '' === ( $schema['notifications']['to'] ?? '' ) ) {
			$schema['notifications']['to'] = (string) get_option( 'admin_email' );
		}

		$now = current_time( 'mysql', true );
		$row = array(
			'uuid'        => wp_generate_uuid4(),
			'title'       => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'status'      => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'published',
			'form_schema' => wp_json_encode( $schema ),
			'settings'    => wp_json_encode( isset( $data['settings'] ) ? $data['settings'] : array() ),
			'ai_prompt'   => isset( $data['ai_prompt'] ) ? wp_kses_post( $data['ai_prompt'] ) : null,
			'author_id'   => get_current_user_id(),
			'created_at'  => $now,
			'updated_at'  => $now,
		);

		$wpdb->insert( Schema::forms_table(), $row );
		$id = (int) $wpdb->insert_id;
		return $id ? $this->get( $id ) : null;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$row = array();
		if ( array_key_exists( 'title', $data ) ) {
			$row['title'] = sanitize_text_field( $data['title'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$row['status'] = sanitize_key( $data['status'] );
		}
		if ( array_key_exists( 'schema', $data ) ) {
			$row['form_schema'] = wp_json_encode( Schema_Prompt::sanitize_schema( is_array( $data['schema'] ) ? $data['schema'] : array() ) );
		}
		if ( array_key_exists( 'settings', $data ) ) {
			$row['settings'] = wp_json_encode( $data['settings'] );
		}
		if ( array_key_exists( 'ai_prompt', $data ) ) {
			$row['ai_prompt'] = wp_kses_post( (string) $data['ai_prompt'] );
		}
		$row['updated_at'] = current_time( 'mysql', true );

		$wpdb->update( Schema::forms_table(), $row, array( 'id' => (int) $id ) );
		return $this->get( $id );
	}

	public function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::forms_table(), array( 'id' => (int) $id ) );
	}

	public function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::forms_table(), (int) $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function get_by_uuid( $uuid ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE uuid = %s', Schema::forms_table(), $uuid ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function list( array $args = array() ) {
		global $wpdb;
		$limit  = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$offset = isset( $args['page'] ) ? max( 0, ( (int) $args['page'] - 1 ) * $limit ) : 0;
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$results = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE title LIKE %s OR uuid LIKE %s ORDER BY updated_at DESC LIMIT %d OFFSET %d',
					Schema::forms_table(),
					$like,
					$like,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY updated_at DESC LIMIT %d OFFSET %d', Schema::forms_table(), $limit, $offset ),
				ARRAY_A
			);
		}
		return array_map( array( $this, 'hydrate' ), $results ?: array() );
	}

	public function count( array $args = array() ) {
		global $wpdb;
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		if ( '' !== $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			return (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE title LIKE %s OR uuid LIKE %s', Schema::forms_table(), $like, $like )
			);
		}
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::forms_table() ) );
	}

	private function hydrate( array $row ) {
		$row['id']       = (int) $row['id'];
		$row['schema']   = json_decode( $row['form_schema'] ?? '', true ) ?: array();
		$row['settings'] = json_decode( $row['settings'] ?? '', true ) ?: array();
		unset( $row['form_schema'] );
		return $row;
	}
}
