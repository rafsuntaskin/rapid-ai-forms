<?php
/**
 * Plugin Name: Rapid AI Forms — E2E test harness
 * Description: Registers a deterministic stub AI provider so Playwright can
 *   exercise the "Generate with AI" flow without hitting a real provider.
 *   Mounted into the wp-env test site only (see .wp-env.json mappings) — it is
 *   never part of the shipped plugin.
 *
 * @package Rapid_Ai_Forms
 */

defined( 'ABSPATH' ) || exit;

/*
 * mu-plugins load BEFORE regular plugins, so the Provider interface doesn't
 * exist yet at file-parse time. We register the stub from inside the
 * `rapid_ai_forms_register_providers` action (fired from Provider_Manager's
 * constructor, after the plugin has loaded) and use an anonymous class so the
 * `implements` is only evaluated then — never at mu-plugin load.
 */
add_action(
	'rapid_ai_forms_register_providers',
	function ( $manager ) {
		if ( ! interface_exists( '\Rapid_Ai_Forms\Ai\Provider' ) ) {
			return;
		}

		$manager->register(
			new class() implements \Rapid_Ai_Forms\Ai\Provider {

				public function key() {
					return 'e2e_stub';
				}

				public function label() {
					return 'E2E Stub';
				}

				public function generate_form_schema( $prompt, array $options = array() ) {
					return array(
						'title'         => 'Volunteer Signup',
						'submit_label'  => 'Sign up',
						'show_title'    => true,
						'fields'        => array(
							array( 'name' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true ),
							array( 'name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true ),
							array( 'name' => 'availability', 'label' => 'Availability', 'type' => 'textarea', 'required' => false ),
						),
						'notifications' => array( 'enabled' => false ),
					);
				}

				public function generate_text( $system, $prompt, array $options = array() ) {
					return 'label { color: rgb(220, 20, 60); }';
				}

				public function verify( array $options = array() ) {
					return true;
				}
			}
		);
	}
);
