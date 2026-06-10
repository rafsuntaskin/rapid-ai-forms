<?php
/**
 * Integration tests for the public submit endpoint and the admin
 * submissions collection.
 *
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Forms\Form_Repository;
use Rapid_Ai_Forms\Forms\Submission_Repository;

class Test_Rest_Submissions extends WP_UnitTestCase {

	private static $admin_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	private function create_contact_form() {
		return ( new Form_Repository() )->create(
			array(
				'title'  => 'Contact',
				'schema' => array(
					'fields'        => array(
						array(
							'name'     => 'name',
							'label'    => 'Name',
							'type'     => 'text',
							'required' => true,
						),
						array(
							'name'     => 'email',
							'label'    => 'Email',
							'type'     => 'email',
							'required' => true,
						),
						array(
							'name'     => 'message',
							'label'    => 'Message',
							'type'     => 'textarea',
							'required' => false,
						),
					),
					'notifications' => array( 'enabled' => true ),
				),
			)
		);
	}

	private function submit( $uuid, array $body ) {
		$req = new WP_REST_Request( 'POST', "/rapid-ai-forms/v1/submissions/{$uuid}" );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	public function test_submit_missing_required_fields_returns_422_and_writes_no_row() {
		$form = $this->create_contact_form();

		$res = $this->submit( $form['uuid'], array( 'name' => 'Jane' ) );

		$this->assertSame( 422, $res->get_status() );
		$data = $res->get_data();
		$this->assertSame( 'raif_validation', $data['code'] );
		$this->assertArrayHasKey( 'email', $data['data']['fields'] );
		$this->assertArrayNotHasKey( 'name', $data['data']['fields'] );
		$this->assertSame( 0, ( new Submission_Repository() )->count( $form['id'] ) );
	}

	public function test_submit_invalid_email_that_sanitizes_to_empty_is_rejected() {
		$form = $this->create_contact_form();

		$res = $this->submit(
			$form['uuid'],
			array(
				'name'  => 'Jane',
				'email' => 'not-an-email',
			)
		);

		$this->assertSame( 422, $res->get_status() );
		$this->assertArrayHasKey( 'email', $res->get_data()['data']['fields'] );
	}

	public function test_submit_valid_payload_creates_row() {
		$form = $this->create_contact_form();

		$res = $this->submit(
			$form['uuid'],
			array(
				'name'    => 'Jane',
				'email'   => 'jane@example.com',
				'message' => 'Hello',
			)
		);

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 1, ( new Submission_Repository() )->count( $form['id'] ) );
	}

	public function test_repository_list_decodes_data_json() {
		$form = $this->create_contact_form();
		( new Submission_Repository() )->create( $form['id'], array( 'name' => 'Jane' ) );

		$rows = ( new Submission_Repository() )->list( array( 'form_id' => $form['id'] ) );

		// Regression: a by-reference foreach over a temporary used to skip decoding.
		$this->assertIsArray( $rows[0]['data'] );
		$this->assertSame( 'Jane', $rows[0]['data']['name'] );
	}

	public function test_admin_collection_requires_auth() {
		wp_set_current_user( 0 );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/submissions' ) );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_admin_collection_lists_across_forms_with_totals_and_filter() {
		$form_a = $this->create_contact_form();
		$form_b = $this->create_contact_form();
		$repo   = new Submission_Repository();
		$repo->create( $form_a['id'], array( 'name' => 'A' ) );
		$repo->create( $form_b['id'], array( 'name' => 'B1' ) );
		$repo->create( $form_b['id'], array( 'name' => 'B2' ) );

		wp_set_current_user( self::$admin_id );

		$res = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/submissions' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( '3', $res->get_headers()['X-WP-Total'] );
		$this->assertSame( 'Contact', $res->get_data()[0]['form_title'] );

		$req = new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/submissions' );
		$req->set_query_params( array( 'form_id' => $form_b['id'] ) );
		$res = rest_do_request( $req );
		$this->assertSame( '2', $res->get_headers()['X-WP-Total'] );
	}

	public function test_admin_collection_attaches_email_preview() {
		$form = $this->create_contact_form();
		( new Submission_Repository() )->create(
			$form['id'],
			array(
				'name'  => 'Jane',
				'email' => 'jane@example.com',
			)
		);

		wp_set_current_user( self::$admin_id );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/submissions' ) );
		$row = $res->get_data()[0];

		$this->assertIsArray( $row['email'] );
		$this->assertStringContainsString( 'Contact', $row['email']['subject'] );
		$this->assertStringContainsString( 'Name: Jane', $row['email']['body'] );
	}

	public function test_oversized_payload_is_rejected() {
		$form = $this->create_contact_form();
		$res  = $this->submit(
			$form['uuid'],
			array(
				'name'    => 'Jane',
				'email'   => 'jane@example.com',
				'message' => str_repeat( 'a', 70000 ),
			)
		);
		$this->assertSame( 413, $res->get_status() );
	}
}
