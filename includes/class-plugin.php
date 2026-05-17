<?php
/**
 * Main plugin orchestrator.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms;

use WP_AI_Forms\Abilities\Abilities;
use WP_AI_Forms\Admin\Admin;
use WP_AI_Forms\Api\Rest_Controller;
use WP_AI_Forms\Db\Schema;
use WP_AI_Forms\Shortcodes\Form_Shortcode;
use WP_AI_Forms\Frontend\Frontend;

defined( 'ABSPATH' ) || exit;

class Plugin {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	private function boot() {
		load_plugin_textdomain( 'wp-ai-forms', false, dirname( WP_AI_FORMS_BASENAME ) . '/languages' );

		$this->maybe_migrate();

		( new Admin() )->register();
		( new Rest_Controller() )->register();
		( new Form_Shortcode() )->register();
		( new Frontend() )->register();
		( new Abilities() )->register();
	}

	private function maybe_migrate() {
		if ( get_option( 'wp_ai_forms_db_version' ) !== Schema::DB_VERSION ) {
			Schema::install();
		}
	}
}
