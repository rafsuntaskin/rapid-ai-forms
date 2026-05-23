<?php
/**
 * Plugin activation handler.
 *
 * @package Easy_Ai_Forms
 */

namespace Easy_Ai_Forms;

use Easy_Ai_Forms\Db\Schema;

defined( 'ABSPATH' ) || exit;

class Activator {
	public static function activate() {
		Schema::install();
		add_option( 'easy_ai_forms_version', EASY_AI_FORMS_VERSION );
		flush_rewrite_rules();
	}
}
