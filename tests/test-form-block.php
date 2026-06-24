<?php
/**
 * Tests for the rapid-ai-forms/form block and its /forms-list picker endpoint.
 *
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Forms\Form_Repository;

class Test_Form_Block extends WP_UnitTestCase {

	private static $author_id;
	private static $subscriber_id;

	public static function wpSetUpBeforeClass( $factory ) {
		self::$author_id     = $factory->user->create( array( 'role' => 'author' ) );
		self::$subscriber_id = $factory->user->create( array( 'role' => 'subscriber' ) );
	}

	private function make_form() {
		return ( new Form_Repository() )->create(
			array(
				'title'  => 'Block Form',
				'schema' => array(
					'fields'        => array(
						array(
							'name'  => 'email',
							'label' => 'Email',
							'type'  => 'email',
						),
					),
					'notifications' => array( 'enabled' => false ),
				),
			)
		);
	}

	public function test_block_is_registered() {
		$this->assertTrue(
			WP_Block_Type_Registry::get_instance()->is_registered( 'rapid-ai-forms/form' )
		);
	}

	public function test_block_renders_the_form_via_do_blocks() {
		$form = $this->make_form();
		$html = do_blocks( '<!-- wp:rapid-ai-forms/form {"formId":' . $form['id'] . '} /-->' );

		$this->assertStringContainsString( 'raif-form', $html );
		$this->assertStringContainsString( $form['uuid'], $html );
		// Wrapped in the block's own container (block supports).
		$this->assertStringContainsString( 'wp-block-rapid-ai-forms-form', $html );
	}

	public function test_block_renders_nothing_for_missing_or_unset_form() {
		$this->assertStringNotContainsString(
			'raif-form',
			do_blocks( '<!-- wp:rapid-ai-forms/form {"formId":999999} /-->' )
		);
		$this->assertStringNotContainsString(
			'raif-form',
			do_blocks( '<!-- wp:rapid-ai-forms/form /-->' )
		);
	}

	public function test_forms_list_denies_subscribers() {
		wp_set_current_user( self::$subscriber_id );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/forms-list' ) );
		$this->assertSame( 403, $res->get_status() );
	}

	public function test_forms_list_returns_id_and_title_for_editors() {
		$form = $this->make_form();
		wp_set_current_user( self::$author_id );

		$res = rest_do_request( new WP_REST_Request( 'GET', '/rapid-ai-forms/v1/forms-list' ) );
		$this->assertSame( 200, $res->get_status() );

		$data = $res->get_data();
		$this->assertNotEmpty( $data );
		$ids = wp_list_pluck( $data, 'id' );
		$this->assertContains( $form['id'], $ids );
		$this->assertArrayHasKey( 'title', $data[0] );
		// No sensitive fields leak through the lightweight picker list.
		$this->assertArrayNotHasKey( 'schema', $data[0] );
	}
}
