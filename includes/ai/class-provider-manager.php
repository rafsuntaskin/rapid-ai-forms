<?php
/**
 * Resolves and dispatches AI requests to the configured provider.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Ai;

use Rapid_Ai_Forms\Ai\Providers\Anthropic;
use Rapid_Ai_Forms\Ai\Providers\Gemini;
use Rapid_Ai_Forms\Ai\Providers\Managed;
use Rapid_Ai_Forms\Ai\Providers\Openai_Compatible;
use Rapid_Ai_Forms\Ai\Providers\Wp_Ai_Client;

defined( 'ABSPATH' ) || exit;

class Provider_Manager {
	const OPTION_KEY = 'rapid_ai_forms_ai_settings';

	private $providers = array();

	public function __construct() {
		$this->register( new Managed() );
		$this->register( new Anthropic() );
		$this->register( new Gemini() );
		$this->register( new Openai_Compatible() );

		// Only expose the core AI Client provider on WP 7.0+ with AI support enabled.
		if ( Wp_Ai_Client::is_available() ) {
			$this->register( new Wp_Ai_Client() );
		}

		/**
		 * Allow third parties to register additional providers.
		 *
		 * @param Provider_Manager $manager
		 */
		do_action( 'rapid_ai_forms_register_providers', $this );
	}

	public function register( Provider $provider ) {
		$this->providers[ $provider->key() ] = $provider;
	}

	public function get( $key ) {
		return $this->providers[ $key ] ?? null;
	}

	public function all() {
		return $this->providers;
	}

	public function settings() {
		$defaults = array(
			'active_provider' => 'openai_compatible',
			'providers'       => array(
				'managed'           => array(
					'site_token' => '',
					'site_url'   => '',
				),
				'anthropic'         => array(
					'api_key' => '',
					'model'   => 'claude-sonnet-4-6',
				),
				'gemini'            => array(
					'api_key' => '',
					'model'   => 'gemini-2.0-flash',
				),
				'openai_compatible' => array(
					'api_key'  => '',
					'base_url' => 'https://api.openai.com/v1',
					'model'    => 'gpt-4o-mini',
				),
			),
		);
		// Core AI Client has no per-plugin credentials — the connector lives in core.
		if ( Wp_Ai_Client::is_available() ) {
			$defaults['providers']['wp_ai_client'] = array();
		}
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}

	public function save_settings( array $settings ) {
		// Stored autoload=no — API keys aren't needed on every page load,
		// only when an admin invokes AI features. Keeps the keys out of
		// the wp_load_alloptions() cache.
		update_option( self::OPTION_KEY, $settings, false );
	}

	public function generate_form_schema( $prompt, $current_schema = null ) {
		$settings = $this->settings();
		$provider = $this->get( $settings['active_provider'] );

		if ( ! $provider ) {
			return new \WP_Error( 'raif_no_provider', __( 'No AI provider configured.', 'rapid-ai-forms' ) );
		}

		$options = $settings['providers'][ $settings['active_provider'] ] ?? array();

		// When editing, prepend the current schema so the model knows what to preserve.
		if ( is_array( $current_schema ) && ! empty( $current_schema['fields'] ) ) {
			$prompt = sprintf(
				"Current form schema:\n%s\n\nUser request:\n%s",
				wp_json_encode( $current_schema, JSON_PRETTY_PRINT ),
				$prompt
			);
		}

		return $provider->generate_form_schema( $prompt, $options );
	}

	/**
	 * Free-form text generation through the active provider.
	 *
	 * @param string $system System instruction.
	 * @param string $prompt User message.
	 * @return string|\WP_Error
	 */
	public function generate_text( $system, $prompt ) {
		$settings = $this->settings();
		$provider = $this->get( $settings['active_provider'] );

		if ( ! $provider ) {
			return new \WP_Error( 'raif_no_provider', __( 'No AI provider configured.', 'rapid-ai-forms' ) );
		}

		// Third-party providers registered before generate_text() joined the
		// interface may not implement it.
		if ( ! method_exists( $provider, 'generate_text' ) ) {
			return new \WP_Error( 'raif_not_supported', __( 'The active AI provider does not support text generation.', 'rapid-ai-forms' ) );
		}

		$options = $settings['providers'][ $settings['active_provider'] ] ?? array();
		return $provider->generate_text( $system, $prompt, $options );
	}
}
