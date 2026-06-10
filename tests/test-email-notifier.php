<?php
/**
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Notifications\Email_Notifier;

class Test_Email_Notifier extends WP_UnitTestCase {

	private function form( array $notifications ) {
		return array(
			'title'  => 'Contact',
			'schema' => array(
				'fields'        => array(
					array(
						'name'  => 'name',
						'label' => 'Name',
						'type'  => 'text',
					),
					array(
						'name'  => 'tags',
						'label' => 'Tags',
						'type'  => 'checkbox_group',
					),
				),
				'notifications' => $notifications,
			),
		);
	}

	public function test_compose_returns_null_when_disabled() {
		$notifier = new Email_Notifier();
		$this->assertNull( $notifier->compose( $this->form( array( 'enabled' => false ) ), array() ) );
		$this->assertNull( $notifier->compose( array( 'title' => 'X', 'schema' => array() ), array() ) );
	}

	public function test_compose_defaults_subject_and_body() {
		$message = ( new Email_Notifier() )->compose(
			$this->form( array( 'enabled' => true ) ),
			array( 'name' => 'Jane' )
		);

		$this->assertSame( 'New submission: Contact', $message['subject'] );
		$this->assertStringContainsString( 'Name: Jane', $message['body'] );
	}

	public function test_compose_resolves_mail_tags_and_arrays() {
		$message = ( new Email_Notifier() )->compose(
			$this->form(
				array(
					'enabled' => true,
					'subject' => 'From {name} via {form_title}',
					'body'    => "Tags: {tags}\n{all_fields}",
				)
			),
			array(
				'name' => 'Jane',
				'tags' => array( 'a', 'b' ),
			)
		);

		$this->assertSame( 'From Jane via Contact', $message['subject'] );
		$this->assertStringContainsString( 'Tags: a, b', $message['body'] );
		$this->assertStringContainsString( "Name: Jane\nTags: a, b", $message['body'] );
	}
}
