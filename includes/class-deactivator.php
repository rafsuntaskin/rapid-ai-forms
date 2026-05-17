<?php
/**
 * Plugin deactivation handler.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms;

defined( 'ABSPATH' ) || exit;

class Deactivator {
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
