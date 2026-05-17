<?php
/**
 * Form CRUD repository.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Forms;

use WP_AI_Forms\Db\Schema;

defined( 'ABSPATH' ) || exit;

class Form_Repository {

	public function create( array $data ) {
		global $wpdb;

		$now = current_time( 'mysql', true );
		$row = [
			'uuid'        => wp_generate_uuid4(),
			'title'       => isset( $data['title'] ) ? sanitize_text_field( $data['title'] ) : '',
			'status'      => isset( $data['status'] ) ? sanitize_key( $data['status'] ) : 'draft',
			'form_schema' => wp_json_encode( isset( $data['schema'] ) ? $data['schema'] : [] ),
			'settings'    => wp_json_encode( isset( $data['settings'] ) ? $data['settings'] : [] ),
			'ai_prompt'   => isset( $data['ai_prompt'] ) ? wp_kses_post( $data['ai_prompt'] ) : null,
			'author_id'   => get_current_user_id(),
			'created_at'  => $now,
			'updated_at'  => $now,
		];

		$wpdb->insert( Schema::forms_table(), $row );
		$id = (int) $wpdb->insert_id;
		return $id ? $this->get( $id ) : null;
	}

	public function update( $id, array $data ) {
		global $wpdb;

		$row = [];
		if ( array_key_exists( 'title', $data ) ) {
			$row['title'] = sanitize_text_field( $data['title'] );
		}
		if ( array_key_exists( 'status', $data ) ) {
			$row['status'] = sanitize_key( $data['status'] );
		}
		if ( array_key_exists( 'schema', $data ) ) {
			$row['form_schema'] = wp_json_encode( $data['schema'] );
		}
		if ( array_key_exists( 'settings', $data ) ) {
			$row['settings'] = wp_json_encode( $data['settings'] );
		}
		if ( array_key_exists( 'ai_prompt', $data ) ) {
			$row['ai_prompt'] = wp_kses_post( (string) $data['ai_prompt'] );
		}
		$row['updated_at'] = current_time( 'mysql', true );

		$wpdb->update( Schema::forms_table(), $row, [ 'id' => (int) $id ] );
		return $this->get( $id );
	}

	public function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( Schema::forms_table(), [ 'id' => (int) $id ] );
	}

	public function get( $id ) {
		global $wpdb;
		$table = Schema::forms_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function get_by_uuid( $uuid ) {
		global $wpdb;
		$table = Schema::forms_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE uuid = %s", $uuid ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function list( array $args = [] ) {
		global $wpdb;
		$table   = Schema::forms_table();
		$limit   = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$offset  = isset( $args['page'] ) ? max( 0, ( (int) $args['page'] - 1 ) * $limit ) : 0;
		$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY updated_at DESC LIMIT %d OFFSET %d", $limit, $offset ), ARRAY_A );
		return array_map( [ $this, 'hydrate' ], $results ?: [] );
	}

	private function hydrate( array $row ) {
		$row['id']       = (int) $row['id'];
		$row['schema']   = json_decode( $row['form_schema'] ?? '', true ) ?: [];
		$row['settings'] = json_decode( $row['settings'] ?? '', true ) ?: [];
		unset( $row['form_schema'] );
		return $row;
	}
}
