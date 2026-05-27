<?php
/**
 * Plugin activation handler.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms;

use Rapid_Ai_Forms\Db\Schema;

defined( 'ABSPATH' ) || exit;

class Activator {
	public static function activate() {
		Schema::install();
		add_option( 'rapid_ai_forms_version', RAPID_AI_FORMS_VERSION );
		flush_rewrite_rules();
	}
}
