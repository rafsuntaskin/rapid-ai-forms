<?php
/**
 * Plugin activation handler.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms;

use WP_AI_Forms\Db\Schema;

defined( 'ABSPATH' ) || exit;

class Activator {
	public static function activate() {
		Schema::install();
		add_option( 'wp_ai_forms_version', WP_AI_FORMS_VERSION );
		flush_rewrite_rules();
	}
}
