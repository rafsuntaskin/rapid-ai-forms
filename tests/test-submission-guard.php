<?php
/**
 * Tests for the anti-abuse submission guard: token issuance/verification,
 * honeypot, time-trap, Origin check, and per-IP rate limit.
 *
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Forms\Form_Repository;
use Rapid_Ai_Forms\Forms\Submission_Guard;
use Rapid_Ai_Forms\Forms\Submission_Repository;

class Test_Submission_Guard extends WP_UnitTestCase {

	private function make_form() {
		return ( new Form_Repository() )->create(
			array(
				'title'  => 'Contact',
				'schema' => array(
					'fields'        => array(
						array(
							'name'     => 'email',
							'label'    => 'Email',
							'type'     => 'email',
							'required' => true,
						),
					),
					'notifications' => array( 'enabled' => false ),
				),
			)
		);
	}

	/** A signed token aged `$age` seconds (past the time-trap, within TTL). */
	private function aged_token( $uuid, $age = 5 ) {
		$ts  = time() - $age;
		$sig = hash_hmac( 'sha256', $uuid . '|' . $ts, Submission_Guard::secret() );
		return base64_encode( $ts . '.' . $sig );
	}

	private function post( $uuid, array $body, array $headers = array() ) {
		$req = new WP_REST_Request( 'POST', "/rapid-ai-forms/v1/submissions/{$uuid}" );
		$req->set_header( 'Content-Type', 'application/json' );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$req->set_body( wp_json_encode( $body ) );
		return rest_do_request( $req );
	}

	private function count_rows( $form_id ) {
		return ( new Submission_Repository() )->count( $form_id );
	}

	/* ---- token endpoint ---- */

	public function test_form_token_endpoint_returns_signed_token() {
		$form = $this->make_form();
		$res  = rest_do_request( new WP_REST_Request( 'GET', "/rapid-ai-forms/v1/form-token/{$form['uuid']}" ) );

		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertNotEmpty( $data['token'] );
		// The issued token verifies for this uuid.
		$this->assertIsInt( Submission_Guard::verify_token( $form['uuid'], $data['token'] ) );
	}

	public function test_form_token_unknown_form_is_404() {
		$res = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/form-token/deadbeef-0000' ) );
		$this->assertSame( 404, $res->get_status() );
	}

	/* ---- token enforcement on submit ---- */

	public function test_cold_submit_without_token_is_forbidden() {
		$form = $this->make_form();
		$res  = $this->post( $form['uuid'], array( 'email' => 'a@b.com' ) );

		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'raif_bad_token', $res->get_data()['code'] );
		$this->assertSame( 0, $this->count_rows( $form['id'] ) );
	}

	public function test_forged_token_is_forbidden() {
		$form = $this->make_form();
		$res  = $this->post( $form['uuid'], array( 'email' => 'a@b.com', '_raif_token' => base64_encode( time() . '.deadbeef' ) ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'raif_bad_token', $res->get_data()['code'] );
	}

	public function test_expired_token_is_forbidden() {
		$form  = $this->make_form();
		$token = $this->aged_token( $form['uuid'], Submission_Guard::TOKEN_TTL + 60 );
		$res   = $this->post( $form['uuid'], array( 'email' => 'a@b.com', '_raif_token' => $token ) );
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'raif_expired_token', $res->get_data()['code'] );
	}

	public function test_token_is_bound_to_its_form() {
		$a = $this->make_form();
		$b = $this->make_form();
		// A token minted for form A must not work for form B.
		$res = $this->post( $b['uuid'], array( 'email' => 'a@b.com', '_raif_token' => $this->aged_token( $a['uuid'] ) ) );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_valid_aged_token_submits() {
		$form = $this->make_form();
		$res  = $this->post( $form['uuid'], array( 'email' => 'jane@example.com', '_raif_token' => $this->aged_token( $form['uuid'] ) ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );
		$this->assertSame( 1, $this->count_rows( $form['id'] ) );
	}

	/* ---- traps (silent accept-and-discard) ---- */

	public function test_honeypot_is_accepted_but_discarded() {
		$form = $this->make_form();
		$res  = $this->post(
			$form['uuid'],
			array(
				'email'       => 'jane@example.com',
				'raif_hp'     => 'i am a bot',
				'_raif_token' => $this->aged_token( $form['uuid'] ),
			)
		);

		$this->assertSame( 200, $res->get_status() );  // looks successful…
		$this->assertSame( 0, $this->count_rows( $form['id'] ) ); // …but nothing stored.
	}

	public function test_time_trap_discards_too_fast_submits() {
		$form = $this->make_form();
		// Fresh token (issued ~now) submitted immediately → under MIN_FILL_SECONDS.
		$token = Submission_Guard::mint( $form['uuid'] )['token'];
		$res   = $this->post( $form['uuid'], array( 'email' => 'jane@example.com', '_raif_token' => $token ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 0, $this->count_rows( $form['id'] ) );
	}

	/* ---- origin + rate limit ---- */

	public function test_cross_origin_is_forbidden() {
		$form = $this->make_form();
		$res  = $this->post(
			$form['uuid'],
			array( 'email' => 'a@b.com', '_raif_token' => $this->aged_token( $form['uuid'] ) ),
			array( 'origin' => 'https://evil.example.org' )
		);
		$this->assertSame( 403, $res->get_status() );
		$this->assertSame( 'raif_bad_origin', $res->get_data()['code'] );
	}

	public function test_same_origin_passes() {
		$form = $this->make_form();
		$res  = $this->post(
			$form['uuid'],
			array( 'email' => 'a@b.com', '_raif_token' => $this->aged_token( $form['uuid'] ) ),
			array( 'origin' => home_url() )
		);
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_rate_limit_returns_429_over_threshold() {
		add_filter( 'rapid_ai_forms_submission_rate_limit', static fn() => 2 );
		$form = $this->make_form();

		$ok1 = $this->post( $form['uuid'], array( 'email' => 'a@b.com', '_raif_token' => $this->aged_token( $form['uuid'] ) ) );
		$ok2 = $this->post( $form['uuid'], array( 'email' => 'a@b.com', '_raif_token' => $this->aged_token( $form['uuid'] ) ) );
		$blocked = $this->post( $form['uuid'], array( 'email' => 'a@b.com', '_raif_token' => $this->aged_token( $form['uuid'] ) ) );

		$this->assertSame( 200, $ok1->get_status() );
		$this->assertSame( 200, $ok2->get_status() );
		$this->assertSame( 429, $blocked->get_status() );
		$this->assertSame( 'raif_rate_limited', $blocked->get_data()['code'] );
	}
}
