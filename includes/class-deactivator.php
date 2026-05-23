<?php
/**
 * Plugin deactivation handler.
 *
 * @package Easy_Ai_Forms
 */

namespace Easy_Ai_Forms;

defined( 'ABSPATH' ) || exit;

class Deactivator {
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
