<?php
/**
 * Plugin Name:       WP AI Forms
 * Description:       AI-powered form builder for WordPress. Generate forms with natural language and render them via shortcodes. Bring your own AI provider key (Anthropic, Gemini, or any OpenAI-compatible endpoint).
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Rafsun Taskin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-ai-forms
 * Domain Path:       /languages
 *
 * @package WP_AI_Forms
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_AI_FORMS_VERSION', '0.1.0' );
define( 'WP_AI_FORMS_FILE', __FILE__ );
define( 'WP_AI_FORMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_AI_FORMS_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_AI_FORMS_BASENAME', plugin_basename( __FILE__ ) );

require_once WP_AI_FORMS_PATH . 'includes/class-autoloader.php';
WP_AI_Forms\Autoloader::register();

register_activation_hook( __FILE__, array( WP_AI_Forms\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( WP_AI_Forms\Deactivator::class, 'deactivate' ) );

add_action( 'plugins_loaded', array( WP_AI_Forms\Plugin::class, 'instance' ) );
