<?php
/**
 * Idempotent fixture seeder, run inside wp-env via `wp eval-file`.
 *
 * Creates a contact form (required name + email, optional message) and a
 * published page that embeds it with the shortcode, then writes their ids/URLs
 * to tests/e2e/.fixtures.json (the plugin dir is mounted, so the host sees it).
 *
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Db\Schema;
use Rapid_Ai_Forms\Forms\Form_Repository;

if ( ! class_exists( '\Rapid_Ai_Forms\Forms\Form_Repository' ) ) {
	fwrite( STDERR, "Rapid AI Forms not active\n" );
	exit( 1 );
}

global $wpdb;
$repo = new Form_Repository();

// Select the stub AI provider (registered by the e2e mu-plugin) so the admin
// "Generate with AI" flow is deterministic.
$settings = get_option( 'rapid_ai_forms_ai_settings', array() );
if ( ! is_array( $settings ) ) {
	$settings = array();
}
$settings['active_provider'] = 'e2e_stub';
// A non-empty api_key makes get_settings() report the provider as
// `configured`, which is what un-gates the admin "Generate with AI" prompt.
$settings['providers']['e2e_stub'] = array( 'api_key' => 'stub-configured' );
update_option( 'rapid_ai_forms_ai_settings', $settings, false );

// --- Clean previous E2E fixtures so re-runs start from a known state. ---
$prior = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE title LIKE %s', Schema::forms_table(), 'E2E %' ) );
foreach ( $prior as $id ) {
	$repo->delete( (int) $id );
}
$old_page = get_page_by_path( 'e2e-contact-form', OBJECT, 'page' );
if ( $old_page ) {
	wp_delete_post( $old_page->ID, true );
}

// --- Contact form: name* + email* + optional message (textarea). ---
$form = $repo->create(
	array(
		'title'  => 'E2E Contact Form',
		'schema' => array(
			'fields'        => array(
				array( 'name' => 'your_name', 'label' => 'Your name', 'type' => 'text', 'required' => true ),
				array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true ),
				array( 'name' => 'message', 'label' => 'Message', 'type' => 'textarea', 'required' => false ),
			),
			'notifications' => array( 'enabled' => false ),
		),
	)
);
$form_id   = (int) $form['id'];
$form_uuid = (string) $form['uuid'];

// --- Published page embedding the form via shortcode. ---
$page_id = wp_insert_post(
	array(
		'post_title'   => 'E2E Contact Form Page',
		'post_name'    => 'e2e-contact-form',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => sprintf( '[rapid_ai_form id="%d"]', $form_id ),
	)
);

$fixtures = array(
	'contactFormId'   => $form_id,
	'contactFormUuid' => $form_uuid,
	'contactPageUrl'  => get_permalink( $page_id ),
	'seededAt'        => gmdate( 'c' ),
);

$out = dirname( __DIR__, 1 ) . '/e2e/.fixtures.json';
// __DIR__ is .../tests/e2e — write the fixtures file right here.
file_put_contents( __DIR__ . '/.fixtures.json', wp_json_encode( $fixtures, JSON_PRETTY_PRINT ) );
fwrite( STDOUT, "SEEDED " . wp_json_encode( $fixtures ) . "\n" );
