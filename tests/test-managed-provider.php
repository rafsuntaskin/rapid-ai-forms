<?php
/**
 * Tests for the Rapid AI Cloud (hosted) provider, its handshake REST
 * routes, error mapping, and secret masking.
 *
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Ai\Provider_Manager;
use Rapid_Ai_Forms\Ai\Providers\Managed;

class Test_Managed_Provider extends WP_UnitTestCase {

	private static $admin_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$admin_id = $factory->user->create( array( 'role' => 'administrator' ) );
	}

	public function set_up() {
		parent::set_up();
		// Enable the gated provider, then rebuild the REST server so its
		// /managed/* routes register for this test.
		add_filter( 'rapid_ai_forms_managed_enabled', '__return_true' );
		global $wp_rest_server;
		$wp_rest_server = null;
		rest_get_server();
	}

	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/* ---- helpers ---- */

	/** Short-circuit outbound HTTP with a handler keyed on URL/args. */
	private function stub_http( callable $handler ) {
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( $handler ) {
				$result = $handler( $url, $args );
				return null === $result ? $pre : $result;
			},
			10,
			3
		);
	}

	private function http_response( $code, array $body ) {
		return array(
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'body'     => wp_json_encode( $body ),
			'headers'  => array(),
		);
	}

	private function store_token( $token = 'tok_123' ) {
		$manager  = new Provider_Manager();
		$settings = $manager->settings();
		$settings['providers']['managed']['site_token'] = $token;
		$settings['providers']['managed']['site_url']   = home_url();
		$manager->save_settings( $settings );
	}

	/* ---- gating ---- */

	public function test_provider_gated_off_by_default() {
		remove_filter( 'rapid_ai_forms_managed_enabled', '__return_true' );
		$manager = new Provider_Manager();
		$this->assertNull( $manager->get( 'managed' ) );
		$this->assertArrayNotHasKey( 'managed', $manager->settings()['providers'] );
	}

	public function test_provider_registered_when_enabled() {
		$this->assertInstanceOf( Managed::class, ( new Provider_Manager() )->get( 'managed' ) );
	}

	/* ---- verify route (single-use nonce) ---- */

	public function test_verify_route_single_use() {
		set_transient( 'raif_managed_nonce', 'thenonce', 300 );

		$req = new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/managed/verify' );
		$req->set_query_params( array( 'nonce' => 'thenonce' ) );

		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertTrue( $res->get_data()['ok'] );

		// Nonce is consumed — a replay fails.
		$res2 = rest_do_request( $req );
		$this->assertSame( 403, $res2->get_status() );
	}

	public function test_verify_route_rejects_mismatch() {
		set_transient( 'raif_managed_nonce', 'right', 300 );
		$req = new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/managed/verify' );
		$req->set_query_params( array( 'nonce' => 'wrong' ) );

		$res = rest_do_request( $req );
		$this->assertSame( 403, $res->get_status() );
		// Transient survives a failed attempt (only a match consumes it).
		$this->assertSame( 'right', get_transient( 'raif_managed_nonce' ) );
	}

	/* ---- register handshake ---- */

	public function test_register_requires_auth() {
		wp_set_current_user( 0 );
		$res = rest_do_request( new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/managed/register' ) );
		$this->assertSame( 401, $res->get_status() );
	}

	public function test_register_persists_token_and_returns_status() {
		wp_set_current_user( self::$admin_id );
		$this->stub_http(
			function ( $url ) {
				if ( false !== strpos( $url, '/v1/register' ) ) {
					return $this->http_response( 200, array( 'token' => 'tok_abc' ) );
				}
				if ( false !== strpos( $url, '/v1/status' ) ) {
					return $this->http_response(
						200,
						array(
							'valid'               => true,
							'free_remaining'      => 4,
							'free_allowance'      => 5,
							'free_renews_at'      => '2026-07-01',
							'purchased_remaining' => 0,
							'account_linked'      => false,
							'manage_url'          => 'https://example.com/dashboard',
						)
					);
				}
				return null;
			}
		);

		$res = rest_do_request( new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/managed/register' ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['connected'] );
		$this->assertSame( 4, $data['free_remaining'] );

		// Token persisted unmasked, bound to this site.
		$opt = get_option( 'rapid_ai_forms_ai_settings' );
		$this->assertSame( 'tok_abc', $opt['providers']['managed']['site_token'] );
		$this->assertSame( home_url(), $opt['providers']['managed']['site_url'] );

		// The handshake nonce was cleaned up.
		$this->assertFalse( get_transient( 'raif_managed_nonce' ) );
	}

	public function test_register_surfaces_unreachable_error() {
		wp_set_current_user( self::$admin_id );
		$this->stub_http(
			fn( $url ) => false !== strpos( $url, '/v1/register' )
				? $this->http_response( 400, array( 'message' => 'Could not verify this site.' ) )
				: null
		);

		$res = rest_do_request( new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/managed/register' ) );
		$this->assertSame( 400, $res->get_status() );
		$this->assertSame( 'raif_managed_error', $res->get_data()['code'] );

		// Nothing stored on failure.
		$opt = get_option( 'rapid_ai_forms_ai_settings' );
		$this->assertSame( '', $opt['providers']['managed']['site_token'] ?? '' );
	}

	/* ---- error mapping (provider unit) ---- */

	public function test_quota_error_maps_to_neutral_message() {
		$this->stub_http(
			fn( $url ) => false !== strpos( $url, '/v1/generate-form' )
				? $this->http_response( 402, array( 'message' => 'Monthly free limit reached.' ) )
				: null
		);

		$err = ( new Managed() )->generate_form_schema( 'a contact form', array( 'site_token' => 'tok' ) );
		$this->assertWPError( $err );
		$this->assertSame( 'raif_quota_reached', $err->get_error_code() );
	}

	public function test_401_maps_to_reconnect() {
		$this->stub_http(
			fn( $url ) => false !== strpos( $url, '/v1/status' )
				? $this->http_response( 401, array( 'message' => 'Invalid token.' ) )
				: null
		);

		$err = ( new Managed() )->status( array( 'site_token' => 'tok' ) );
		$this->assertWPError( $err );
		$this->assertSame( 'raif_reconnect', $err->get_error_code() );
	}

	public function test_5xx_maps_to_transient_error() {
		$this->stub_http(
			fn( $url ) => false !== strpos( $url, '/v1/generate-text' )
				? $this->http_response( 503, array( 'message' => 'down' ) )
				: null
		);

		$err = ( new Managed() )->generate_text( 'sys', 'prompt', array( 'site_token' => 'tok' ) );
		$this->assertWPError( $err );
		$this->assertSame( 'raif_managed_server', $err->get_error_code() );
	}

	public function test_request_attaches_auth_headers() {
		$captured = array();
		$this->stub_http(
			function ( $url, $args ) use ( &$captured ) {
				if ( false !== strpos( $url, '/v1/status' ) ) {
					$captured = $args['headers'];
					return $this->http_response( 200, array( 'valid' => true ) );
				}
				return null;
			}
		);

		( new Managed() )->status( array( 'site_token' => 'tok_xyz' ) );
		$this->assertSame( 'Bearer tok_xyz', $captured['Authorization'] );
		$this->assertSame( home_url(), $captured['X-Site-URL'] );
	}

	/* ---- secret masking + disconnect ---- */

	public function test_site_token_never_in_get_settings() {
		$this->store_token( 'secret_tok' );
		wp_set_current_user( self::$admin_id );

		$res     = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/settings' ) );
		$managed = $res->get_data()['providers']['managed'];

		$this->assertSame( '', $managed['site_token'] );
		$this->assertTrue( $managed['site_token_set'] );
		$this->assertTrue( $managed['connected'] );
		$this->assertTrue( $managed['configured'] );
	}

	public function test_disconnect_clears_token() {
		$this->store_token();
		wp_set_current_user( self::$admin_id );

		$res = rest_do_request( new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/managed/disconnect' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['connected'] );

		$opt = get_option( 'rapid_ai_forms_ai_settings' );
		$this->assertSame( '', $opt['providers']['managed']['site_token'] );
		$this->assertSame( '', $opt['providers']['managed']['site_url'] );
	}

	private function activate_managed( $token = 'tok' ) {
		$manager  = new Provider_Manager();
		$settings = $manager->settings();
		$settings['active_provider'] = 'managed';
		$settings['providers']['managed']['site_token'] = $token;
		$settings['providers']['managed']['site_url']   = home_url();
		$manager->save_settings( $settings );
	}

	private function ai_generate_request( $prompt = 'a contact form' ) {
		$req = new WP_REST_Request( 'POST', '/rapid-ai-forms/v1/ai/generate' );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'prompt' => $prompt ) ) );
		return rest_do_request( $req );
	}

	public function test_quota_error_surfaces_as_402_through_ai_generate() {
		$this->activate_managed();
		wp_set_current_user( self::$admin_id );
		$this->stub_http(
			fn( $url ) => false !== strpos( $url, '/v1/generate-form' )
				? $this->http_response( 402, array( 'message' => 'Monthly free limit reached.' ) )
				: null
		);

		$res = $this->ai_generate_request();
		// The provider's 402 is preserved as the REST status (not flattened to 400).
		$this->assertSame( 402, $res->get_status() );
		$this->assertSame( 'raif_quota_reached', $res->get_data()['code'] );
	}

	public function test_successful_generation_busts_status_cache() {
		$this->activate_managed();
		wp_set_current_user( self::$admin_id );
		set_transient( 'raif_managed_status', array( 'connected' => true, 'free_remaining' => 5 ), 300 );
		$this->stub_http(
			fn( $url ) => false !== strpos( $url, '/v1/generate-form' )
				? $this->http_response(
					200,
					array(
						'schema' => array( 'title' => 'T', 'fields' => array() ),
						'usage'  => array( 'free_remaining' => 4 ),
					)
				)
				: null
		);

		$res = $this->ai_generate_request();
		$this->assertSame( 200, $res->get_status() );
		// The stale cached meter is dropped so the next status load refetches.
		$this->assertFalse( get_transient( 'raif_managed_status' ) );
	}

	public function test_status_without_token_reports_disconnected_without_http() {
		wp_set_current_user( self::$admin_id );
		// No HTTP stub: a network call here would error, proving we short-circuit.
		$this->stub_http(
			static function () {
				return new WP_Error( 'should_not_be_called', 'network must not be hit' );
			}
		);

		$res = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/managed/status' ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertFalse( $res->get_data()['connected'] );
	}
}
