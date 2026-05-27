<?php
/**
 * Main plugin orchestrator.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms;

use Rapid_Ai_Forms\Abilities\Abilities;
use Rapid_Ai_Forms\Admin\Admin;
use Rapid_Ai_Forms\Api\Rest_Controller;
use Rapid_Ai_Forms\Db\Schema;
use Rapid_Ai_Forms\Notifications\Email_Notifier;
use Rapid_Ai_Forms\Shortcodes\Form_Shortcode;
use Rapid_Ai_Forms\Frontend\Frontend;

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
		// Translations: wp.org auto-loads them for hosted plugins (WP 4.6+).
		$this->maybe_migrate();

		( new Admin() )->register();
		( new Rest_Controller() )->register();
		( new Form_Shortcode() )->register();
		( new Frontend() )->register();
		( new Abilities() )->register();
		( new Email_Notifier() )->register();
	}

	private function maybe_migrate() {
		if ( get_option( 'rapid_ai_forms_db_version' ) !== Schema::DB_VERSION ) {
			Schema::install();
		}
	}
}
