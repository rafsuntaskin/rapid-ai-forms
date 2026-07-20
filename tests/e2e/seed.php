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
use Rapid_Ai_Forms\Forms\Submission_Repository;

if ( ! class_exists( '\Rapid_Ai_Forms\Forms\Form_Repository' ) ) {
	fwrite( STDERR, "Rapid AI Forms not active\n" );
	exit( 1 );
}

// Submissions record REMOTE_ADDR / HTTP_USER_AGENT from $_SERVER; stub them so
// the seeded rows carry an IP + user agent (scenario 5 asserts those modal rows).
$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
$_SERVER['HTTP_USER_AGENT'] = 'E2E-Agent/1.0';

// Recognizable seeded values the submissions-dashboard spec asserts against.
const E2E_CONTACT_MESSAGE = 'alpha-form-a-msg';
const E2E_RSVP_GUEST      = 'Bravo Guest';

global $wpdb;
$repo     = new Form_Repository();
$sub_repo = new Submission_Repository();

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
// A saved BYOK key so the settings spec can assert GET /settings masks it
// (returns api_key_set=true, never the value).
$settings['providers']['openai_compatible'] = array(
	'api_key'  => 'sk-e2e-masked-secret',
	'base_url' => 'https://api.openai.com/v1',
	'model'    => 'gpt-4o-mini',
);
update_option( 'rapid_ai_forms_ai_settings', $settings, false );

// --- Clean previous E2E fixtures so re-runs start from a known state. ---
// delete() doesn't cascade submissions, so purge those rows first.
$prior = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i WHERE title LIKE %s', Schema::forms_table(), 'E2E %' ) );
foreach ( $prior as $id ) {
	$wpdb->delete( Schema::submissions_table(), array( 'form_id' => (int) $id ) );
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

// --- Second form: RSVP with notifications ENABLED and no message field.
// Exercises the labeled-digest excerpt (scenario 3) and the modal email
// preview (scenario 5), and gives the form filter a second option (scenario 4).
$rsvp = $repo->create(
	array(
		'title'  => 'E2E RSVP',
		'schema' => array(
			'fields'        => array(
				array( 'name' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true ),
				array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true ),
				array( 'name' => 'guests', 'label' => 'Guests', 'type' => 'number', 'required' => false ),
			),
			'notifications' => array(
				'enabled' => true,
				'to'      => 'organizer@example.com',
				'subject' => 'New RSVP from {full_name}',
				'body'    => "{all_fields}",
			),
		),
	)
);
$rsvp_id = (int) $rsvp['id'];

// --- Deterministic submissions: one per form (recognizable content). ---
$sub_repo->create(
	$form_id,
	array( 'your_name' => 'Alpha One', 'email' => 'alpha@example.com', 'message' => E2E_CONTACT_MESSAGE )
);
$sub_repo->create(
	$rsvp_id,
	array( 'full_name' => E2E_RSVP_GUEST, 'email' => 'bravo@example.com', 'guests' => '3' )
);

// --- Third form: dedicated to the Style-tab specs (scenarios 7-9) so those
// tests can mutate custom CSS without touching the forms other specs use. ---
$style_form = $repo->create(
	array(
		'title'  => 'E2E Style',
		'schema' => array(
			'fields'        => array(
				array( 'name' => 'your_name', 'label' => 'Your name', 'type' => 'text', 'required' => true ),
				array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true ),
			),
			'notifications' => array( 'enabled' => false ),
		),
	)
);

$fixtures = array(
	'contactFormId'   => $form_id,
	'contactFormUuid' => $form_uuid,
	'contactPageId'   => (int) $page_id,
	'contactPageUrl'  => get_permalink( $page_id ),
	'contactMessage'  => E2E_CONTACT_MESSAGE,
	'rsvpFormId'      => $rsvp_id,
	'rsvpFormTitle'   => 'E2E RSVP',
	'rsvpGuest'       => E2E_RSVP_GUEST,
	'styleFormId'     => (int) $style_form['id'],
	'styleFormUuid'   => (string) $style_form['uuid'],
	'seededAt'        => gmdate( 'c' ),
);

$out = dirname( __DIR__, 1 ) . '/e2e/.fixtures.json';
// __DIR__ is .../tests/e2e — write the fixtures file right here.
file_put_contents( __DIR__ . '/.fixtures.json', wp_json_encode( $fixtures, JSON_PRETTY_PRINT ) );
fwrite( STDOUT, "SEEDED " . wp_json_encode( $fixtures ) . "\n" );
