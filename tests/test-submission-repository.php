<?php
/**
 * Tests for Submission_Repository date-range filtering across list(), count(),
 * and export(), plus the form_id filter and count() int back-compat.
 *
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Db\Schema;
use Rapid_Ai_Forms\Forms\Form_Repository;
use Rapid_Ai_Forms\Forms\Submission_Repository;

class Test_Submission_Repository extends WP_UnitTestCase {

	private $form_id;
	private $repo;

	public function set_up() {
		parent::set_up();
		$this->repo    = new Submission_Repository();
		$this->form_id = ( new Form_Repository() )->create(
			array(
				'title'  => 'Contact',
				'schema' => array(
					'fields'        => array(
						array( 'name' => 'name', 'label' => 'Name', 'type' => 'text' ),
					),
					'notifications' => array( 'enabled' => false ),
				),
			)
		);
	}

	/** Insert a submission then force its created_at (UTC) for date-filter tests. */
	private function seed( $created_at_utc, $name = 'X' ) {
		global $wpdb;
		$id = $this->repo->create( $this->form_id, array( 'name' => $name ) );
		$wpdb->update( Schema::submissions_table(), array( 'created_at' => $created_at_utc ), array( 'id' => $id ) );
		return $id;
	}

	public function test_date_range_is_inclusive_of_both_day_bounds() {
		$this->seed( '2026-06-19 23:30:00', 'before' );
		$this->seed( '2026-06-20 00:00:00', 'from-edge' );
		$this->seed( '2026-06-20 23:59:59', 'to-edge' );
		$this->seed( '2026-06-21 00:30:00', 'after' );

		$args = array( 'date_from' => '2026-06-20', 'date_to' => '2026-06-20' );
		$rows = $this->repo->list( $args );

		$this->assertSame( 2, $this->repo->count( $args ) );
		$names = wp_list_pluck( $rows, 'data' );
		$names = array_map( fn( $d ) => $d['name'], $names );
		sort( $names );
		$this->assertSame( array( 'from-edge', 'to-edge' ), $names );
	}

	public function test_date_from_only_and_date_to_only() {
		$this->seed( '2026-06-18 12:00:00' );
		$this->seed( '2026-06-20 12:00:00' );
		$this->seed( '2026-06-22 12:00:00' );

		$this->assertSame( 2, $this->repo->count( array( 'date_from' => '2026-06-20' ) ) );
		$this->assertSame( 2, $this->repo->count( array( 'date_to' => '2026-06-20' ) ) );
	}

	public function test_invalid_date_is_ignored() {
		$this->seed( '2026-06-20 12:00:00' );
		// Garbage date_from must not filter anything out.
		$this->assertSame( 1, $this->repo->count( array( 'date_from' => 'not-a-date' ) ) );
	}

	public function test_export_returns_all_matching_unpaginated() {
		for ( $i = 0; $i < 25; $i++ ) {
			$this->seed( '2026-06-20 12:00:00', "row-$i" );
		}
		// list() caps at per_page; export() returns the whole filtered set.
		$this->assertCount( 20, $this->repo->list( array( 'per_page' => 20 ) ) );
		$this->assertCount( 25, $this->repo->export( array() ) );
	}

	public function test_count_accepts_bare_form_id_int_backcompat() {
		$other = ( new Form_Repository() )->create(
			array( 'title' => 'Other', 'schema' => array( 'fields' => array(), 'notifications' => array( 'enabled' => false ) ) )
		);
		$this->seed( '2026-06-20 12:00:00' );
		$this->repo->create( $other, array() );

		$this->assertSame( 1, $this->repo->count( $this->form_id ) );
		$this->assertSame( 2, $this->repo->count() );
	}

	public function test_export_respects_max_rows_filter() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed( '2026-06-20 12:00:00', "row-$i" );
		}
		add_filter( 'rapid_ai_forms_export_max_rows', fn() => 3 );
		$this->assertCount( 3, $this->repo->export( array() ) );
		remove_all_filters( 'rapid_ai_forms_export_max_rows' );
	}
}
