<?php
/**
 * Tests for Submission_Exporter: CSV/JSON shaping, header/label resolution,
 * value flattening, and the CSV-injection guard.
 *
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Forms\Submission_Exporter;

class Test_Submission_Exporter extends WP_UnitTestCase {

	private function rows() {
		return array(
			array(
				'id'         => 2,
				'form_id'    => 5,
				'form_title' => 'Contact',
				'created_at' => '2026-06-20 10:00:00',
				'ip_address' => '203.0.113.9',
				'user_agent' => 'UA/2',
				'data'       => array(
					'name'     => 'Jane',
					'interest' => array( 'a', 'b' ),
				),
			),
			array(
				'id'         => 1,
				'form_id'    => 5,
				'form_title' => 'Contact',
				'created_at' => '2026-06-19 09:00:00',
				'ip_address' => '198.51.100.4',
				'user_agent' => 'UA/1',
				'data'       => array(
					'name'    => 'Bob',
					'message' => 'Hi, "there"',
				),
			),
		);
	}

	private function parse_csv( $csv ) {
		$rows  = array();
		$lines = preg_split( '/\r\n/', rtrim( $csv, "\r\n" ) );
		foreach ( $lines as $line ) {
			$rows[] = str_getcsv( $line );
		}
		return $rows;
	}

	public function test_csv_header_has_meta_then_union_of_field_keys() {
		$csv    = ( new Submission_Exporter() )->to_csv( $this->rows() );
		$parsed = $this->parse_csv( $csv );

		$this->assertSame(
			array( 'ID', 'Form ID', 'Form', 'Submitted (UTC)', 'IP address', 'name', 'interest', 'message' ),
			$parsed[0]
		);
	}

	public function test_csv_uses_field_labels_when_supplied() {
		$labels = array( 'name' => 'Full name', 'message' => 'Your message' );
		$csv    = ( new Submission_Exporter() )->to_csv( $this->rows(), $labels );
		$header = $this->parse_csv( $csv )[0];

		$this->assertContains( 'Full name', $header );
		$this->assertContains( 'Your message', $header );
		$this->assertNotContains( 'name', $header );
	}

	public function test_csv_row_values_and_array_flattening() {
		$csv    = ( new Submission_Exporter() )->to_csv( $this->rows() );
		$parsed = $this->parse_csv( $csv );

		// Row 1 (Jane): meta cols then name, interest (joined), message (empty).
		$this->assertSame( '2', $parsed[1][0] );
		$this->assertSame( 'Contact', $parsed[1][2] );
		$this->assertSame( 'Jane', $parsed[1][5] );
		$this->assertSame( 'a, b', $parsed[1][6] );
		$this->assertSame( '', $parsed[1][7] );

		// Row 2 (Bob): message with embedded quotes round-trips.
		$this->assertSame( 'Hi, "there"', $parsed[2][7] );
	}

	public function test_csv_neutralizes_formula_injection() {
		$rows = array(
			array(
				'id'         => 1,
				'form_id'    => 1,
				'form_title' => 'F',
				'created_at' => '2026-06-20 00:00:00',
				'ip_address' => '',
				'data'       => array( 'note' => '=SUM(A1:A2)' ),
			),
		);
		$csv  = ( new Submission_Exporter() )->to_csv( $rows );
		$cell = $this->parse_csv( $csv )[1][5];

		// Leading "=" is prefixed with a quote so spreadsheets treat it as text.
		$this->assertSame( "'=SUM(A1:A2)", $cell );
	}

	public function test_json_shape_keeps_structured_data() {
		$json    = ( new Submission_Exporter() )->to_json( $this->rows() );
		$decoded = json_decode( $json, true );

		$this->assertCount( 2, $decoded );
		$this->assertSame( 2, $decoded[0]['id'] );
		$this->assertSame( 5, $decoded[0]['form_id'] );
		$this->assertSame( array( 'a', 'b' ), $decoded[0]['data']['interest'] );
		$this->assertSame( 'Hi, "there"', $decoded[1]['data']['message'] );
		$this->assertArrayHasKey( 'user_agent', $decoded[0] );
	}

	public function test_empty_rows_yield_header_only_csv_and_empty_json() {
		$exporter = new Submission_Exporter();
		$csv      = $this->parse_csv( $exporter->to_csv( array() ) );

		$this->assertCount( 1, $csv ); // header only
		$this->assertSame( 'ID', $csv[0][0] );
		$this->assertSame( '[]', $exporter->to_json( array() ) );
	}
}
