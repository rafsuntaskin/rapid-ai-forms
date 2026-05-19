<?php
/**
 * Admin SPA bootstrap.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Admin;

defined( 'ABSPATH' ) || exit;

class Admin {
	const MENU_SLUG     = 'wp-ai-forms';
	const SETTINGS_SLUG = 'wp-ai-forms-settings';

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'AI Forms', 'wp-ai-forms' ),
			__( 'AI Forms', 'wp-ai-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_app_root' ),
			'dashicons-feedback',
			30
		);

		// Rename the auto-generated submenu duplicate from "AI Forms" to "Forms".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Forms', 'wp-ai-forms' ),
			__( 'Forms', 'wp-ai-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_app_root' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'AI Forms Settings', 'wp-ai-forms' ),
			__( 'Settings', 'wp-ai-forms' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_app_root' )
		);
	}

	public function render_app_root() {
		echo '<div class="wrap"><div id="wp-ai-forms-admin-root"></div></div>';
	}

	public function enqueue_assets( $hook_suffix ) {
		// Reading the ?page= slug from an admin URL — no form data, no nonce needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::MENU_SLUG !== $page && self::SETTINGS_SLUG !== $page ) {
			return;
		}
		unset( $hook_suffix );

		$asset_file = WP_AI_FORMS_PATH . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-data', 'wp-notices' ),
				'version'      => WP_AI_FORMS_VERSION,
			);

		wp_enqueue_script(
			'wp-ai-forms-admin',
			WP_AI_FORMS_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'wp-ai-forms-admin',
			'WP_AI_FORMS_ADMIN',
			array(
				'restUrl'     => esc_url_raw( rest_url( 'wp-ai-forms/v1/' ) ),
				'nonce'       => wp_create_nonce( 'wp_rest' ),
				'adminUrl'    => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
				'settingsUrl' => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
				'pluginUrl'   => WP_AI_FORMS_URL,
				'page'        => $page,
			)
		);

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'wp-ai-forms-admin',
			WP_AI_FORMS_URL . 'build/admin.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_set_script_translations( 'wp-ai-forms-admin', 'wp-ai-forms' );
	}
}
