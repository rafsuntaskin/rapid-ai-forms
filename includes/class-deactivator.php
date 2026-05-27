<?php
/**
 * Plugin deactivation handler.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms;

defined( 'ABSPATH' ) || exit;

class Deactivator {
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
