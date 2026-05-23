<?php
/**
 * Admin SPA bootstrap.
 *
 * @package Easy_Ai_Forms
 */

namespace Easy_Ai_Forms\Admin;

defined( 'ABSPATH' ) || exit;

class Admin {
	const MENU_SLUG     = 'easy-ai-forms';
	const SETTINGS_SLUG = 'easy-ai-forms-settings';

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'AI Forms', 'easy-ai-forms' ),
			__( 'AI Forms', 'easy-ai-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_app_root' ),
			'dashicons-feedback',
			30
		);

		// Rename the auto-generated submenu duplicate from "AI Forms" to "Forms".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Forms', 'easy-ai-forms' ),
			__( 'Forms', 'easy-ai-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_app_root' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'AI Forms Settings', 'easy-ai-forms' ),
			__( 'Settings', 'easy-ai-forms' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_app_root' )
		);
	}

	public function render_app_root() {
		echo '<div class="wrap"><div id="easy-ai-forms-admin-root"></div></div>';
	}

	public function enqueue_assets( $hook_suffix ) {
		// Reading the ?page= slug from an admin URL — no form data, no nonce needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU_SLUG !== $page && self::SETTINGS_SLUG !== $page ) {
			return;
		}
		unset( $hook_suffix );

		$asset_file = EASY_AI_FORMS_PATH . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-data', 'wp-notices' ),
				'version'      => EASY_AI_FORMS_VERSION,
			);

		wp_enqueue_script(
			'easy-ai-forms-admin',
			EASY_AI_FORMS_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'easy-ai-forms-admin',
			'EASY_AI_FORMS_ADMIN',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'easy-ai-forms/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'adminUrl'    => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
				'settingsUrl' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
				'pluginUrl'   => EASY_AI_FORMS_URL,
				'page'        => $page,
			)
		);

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'easy-ai-forms-admin',
			EASY_AI_FORMS_URL . 'build/admin.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_set_script_translations( 'easy-ai-forms-admin', 'easy-ai-forms' );
	}
}
