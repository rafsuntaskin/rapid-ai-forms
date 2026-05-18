<?php
/**
 * Resolves and dispatches AI requests to the configured provider.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Ai;

use WP_AI_Forms\Ai\Providers\Anthropic;
use WP_AI_Forms\Ai\Providers\Gemini;
use WP_AI_Forms\Ai\Providers\Openai_Compatible;

defined( 'ABSPATH' ) || exit;

class Provider_Manager {
	const OPTION_KEY = 'wp_ai_forms_ai_settings';

	private $providers = [];

	public function __construct() {
		$this->register( new Anthropic() );
		$this->register( new Gemini() );
		$this->register( new Openai_Compatible() );

		/**
		 * Allow third parties to register additional providers.
		 *
		 * @param Provider_Manager $manager
		 */
		do_action( 'wp_ai_forms_register_providers', $this );
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
		$defaults = [
			'active_provider' => 'openai_compatible',
			'providers'       => [
				'anthropic'         => [ 'api_key' => '', 'model' => 'claude-sonnet-4-6' ],
				'gemini'            => [ 'api_key' => '', 'model' => 'gemini-2.0-flash' ],
				'openai_compatible' => [ 'api_key' => '', 'base_url' => 'https://api.openai.com/v1', 'model' => 'gpt-4o-mini' ],
			],
		];
		$saved = get_option( self::OPTION_KEY, [] );
		return wp_parse_args( is_array( $saved ) ? $saved : [], $defaults );
	}

	public function save_settings( array $settings ) {
		update_option( self::OPTION_KEY, $settings );
	}

	public function generate_form_schema( $prompt, $current_schema = null ) {
		$settings = $this->settings();
		$provider = $this->get( $settings['active_provider'] );

		if ( ! $provider ) {
			return new \WP_Error( 'wpaif_no_provider', __( 'No AI provider configured.', 'wp-ai-forms' ) );
		}

		$options = $settings['providers'][ $settings['active_provider'] ] ?? [];

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
}
