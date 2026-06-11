<?php
/**
 * Rapid AI Cloud provider: handshake, error mapping, secret handling.
 *
 * All backend HTTP is stubbed with the pre_http_request filter — no
 * network traffic.
 *
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Ai\Provider_Manager;
use Rapid_Ai_Forms\Ai\Providers\Managed;
use Rapid_Ai_Forms\Api\Rest_Controller;

class Test_Managed_Provider extends WP_UnitTestCase {

	private static $admin_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();
		delete_option( Provider_Manager::OPTION_KEY );
		delete_transient( Rest_Controller::MANAGED_NONCE_TRANSIENT );
		delete_transient( Managed::STATUS_TRANSIENT );
	}

	/** Stub the next backend response. */
	private function stub_backend( $code, array $body, &$captured = null ) {
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) use ( $code, $body, &$captured ) {
				$captured = array(
					'url'  => $url,
					'args' => $args,
				);
				return array(
					'headers'  => array(),
					'body'     => wp_json_encode( $body ),
					'response' => array(
						'code'    => $code,
						'message' => '',
					),
				);
			},
			10,
			3
		);
	}

	private function connect_site() {
		$manager  = new Provider_Manager();
		$settings = $manager->settings();
		$settings['providers']['managed']['site_token'] = 'tok_test_123';
		$settings['providers']['managed']['site_url']   = home_url( '/' );
		$manager->save_settings( $settings );
	}

	// ----- launch gate -----

	public function test_provider_hidden_until_launch() {
		$keys = array_keys( ( new Provider_Manager() )->all() );
		$this->assertNotContains( 'managed', $keys );
	}

	public function test_provider_offered_when_filter_enables_it() {
		add_filter( 'rapid_ai_forms_managed_enabled', '__return_true' );
		$keys = array_keys( ( new Provider_Manager() )->all() );
		remove_filter( 'rapid_ai_forms_managed_enabled', '__return_true' );
		$this->assertContains( 'managed', $keys );
	}

	public function test_provider_stays_for_already_connected_sites() {
		$this->connect_site();
		$keys = array_keys( ( new Provider_Manager() )->all() );
		$this->assertContains( 'managed', $keys );
	}

	// ----- verify route (handshake callback target) -----

	public function test_verify_rejects_when_no_nonce_pending() {
		$req = new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/managed/verify' );
		$req->set_query_params( array( 'nonce' => 'whatever' ) );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
	}

	public function test_verify_is_single_use() {
		set_transient( Rest_Controller::MANAGED_NONCE_TRANSIENT, 'nonce-abc', 300 );

		$req = new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/managed/verify' );
		$req->set_query_params( array( 'nonce' => 'nonce-abc' ) );

		$first = rest_do_request( $req );
		$this->assertSame( 200, $first->get_status() );
		$this->assertSame( array( 'ok' => true ), $first->get_data() );

		// Replay must fail — the nonce is consumed.
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
	}

	public function test_verify_rejects_wrong_nonce() {
		set_transient( Rest_Controller::MANAGED_NONCE_TRANSIENT, 'nonce-abc', 300 );
		$req = new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/managed/verify' );
		$req->set_query_params( array( 'nonce' => 'nonce-wrong' ) );
		$this->assertSame( 403, rest_do_request( $req )->get_status() );
		// A failed guess must not consume the real nonce.
		$this->assertSame( 'nonce-abc', get_transient( Rest_Controller::MANAGED_NONCE_TRANSIENT ) );
	}

	// ----- register flow -----

	public function test_register_persists_token_and_site_url() {
		wp_set_current_user( self::$admin_id );
		$this->stub_backend( 200, array( 'token' => 'tok_issued_456' ), $captured );

		$res = rest_do_request( new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/managed/register' ) );

		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['connected'] );

		$saved = ( new Provider_Manager() )->settings()['providers']['managed'];
		$this->assertSame( 'tok_issued_456', $saved['site_token'] );
		$this->assertSame( home_url( '/' ), $saved['site_url'] );

		// The register request carried home_url + a non-empty nonce.
		$sent = json_decode( $captured['args']['body'], true );
		$this->assertSame( home_url( '/' ), $sent['home_url'] );
		$this->assertNotEmpty( $sent['nonce'] );
		$this->assertStringEndsWith( '/v1/register', $captured['url'] );
	}

	public function test_register_unreachable_site_steers_to_byok() {
		wp_set_current_user( self::$admin_id );
		$this->stub_backend(
			400,
			array(
				'code'    => 'site_unreachable',
				'message' => 'callback failed',
			)
		);

		$res = rest_do_request( new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/managed/register' ) );

		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'raif_site_unreachable', $res->get_data()['code'] );
		$this->assertSame( '', ( new Provider_Manager() )->settings()['providers']['managed']['site_token'] );
	}

	public function test_register_requires_admin() {
		wp_set_current_user( 0 );
		$res = rest_do_request( new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/managed/register' ) );
		$this->assertSame( 401, $res->get_status() );
	}

	// ----- error mapping on generation -----

	public function test_quota_exhausted_maps_to_raif_quota_reached() {
		$this->stub_backend( 402, array( 'message' => 'Monthly free limit reached.' ) );

		$result = ( new Managed() )->generate_form_schema( 'a form', array( 'site_token' => 'tok' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'raif_quota_reached', $result->get_error_code() );
		$this->assertSame( 'Monthly free limit reached.', $result->get_error_message() );
	}

	public function test_revoked_token_maps_to_reconnect() {
		$this->stub_backend( 401, array() );

		$result = ( new Managed() )->generate_text( 'sys', 'prompt', array( 'site_token' => 'tok' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'raif_managed_reconnect', $result->get_error_code() );
	}

	public function test_generate_without_token_errors_locally() {
		// No HTTP stub on purpose — it must not even attempt a request.
		$result = ( new Managed() )->generate_form_schema( 'a form', array() );
		$this->assertWPError( $result );
		$this->assertSame( 'raif_not_connected', $result->get_error_code() );
	}

	public function test_successful_generate_returns_sanitized_schema_and_caches_usage() {
		$this->stub_backend(
			200,
			array(
				'schema' => array(
					'title'  => 'Contact',
					'fields' => array(
						array(
							'name'  => 'email',
							'label' => 'Email',
							'type'  => 'email',
						),
					),
				),
				'usage'  => array(
					'free_remaining' => 7,
					'free_allowance' => 30,
				),
			)
		);

		$schema = ( new Managed() )->generate_form_schema( 'contact form', array( 'site_token' => 'tok' ) );

		$this->assertIsArray( $schema );
		$this->assertSame( 'email', $schema['fields'][0]['name'] );

		$cached = get_transient( Managed::STATUS_TRANSIENT );
		$this->assertSame( 7, $cached['free_remaining'] );
	}

	// ----- secret handling -----

	public function test_site_token_never_returned_over_rest() {
		$this->connect_site();
		wp_set_current_user( self::$admin_id );

		$res = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/settings' ) );
		$managed = $res->get_data()['providers']['managed'];

		$this->assertSame( '', $managed['site_token'] );
		$this->assertTrue( $managed['connected'] );
		$this->assertTrue( $managed['configured'] );
		$this->assertStringNotContainsString( 'tok_test_123', wp_json_encode( $res->get_data() ) );
	}

	public function test_settings_update_cannot_overwrite_connection() {
		$this->connect_site();
		wp_set_current_user( self::$admin_id );

		$req = new WP_REST_Request( 'PUT', '/rapid-ai-forms/v1/settings' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body(
			wp_json_encode(
				array(
					'providers' => array(
						'managed' => array(
							'site_token' => 'evil',
							'site_url'   => 'https://attacker.example',
						),
					),
				)
			)
		);
		rest_do_request( $req );

		$saved = ( new Provider_Manager() )->settings()['providers']['managed'];
		$this->assertSame( 'tok_test_123', $saved['site_token'] );
		$this->assertSame( home_url( '/' ), $saved['site_url'] );
	}

	public function test_disconnect_clears_connection_and_active_provider() {
		$this->connect_site();
		$manager  = new Provider_Manager();
		$settings = $manager->settings();
		$settings['active_provider'] = 'managed';
		$manager->save_settings( $settings );

		wp_set_current_user( self::$admin_id );
		$res = rest_do_request( new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/managed/disconnect' ) );

		$this->assertSame( 200, $res->get_status() );
		$saved = ( new Provider_Manager() )->settings();
		$this->assertSame( '', $saved['providers']['managed']['site_token'] );
		$this->assertNotSame( 'managed', $saved['active_provider'] );
	}
}
