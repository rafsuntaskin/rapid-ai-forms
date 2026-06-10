<?php
/**
 * Admin SPA bootstrap.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Admin;

defined( 'ABSPATH' ) || exit;

class Admin {
	const MENU_SLUG        = 'rapid-ai-forms';
	const SUBMISSIONS_SLUG = 'rapid-ai-forms-submissions';
	const SETTINGS_SLUG    = 'rapid-ai-forms-settings';

	public function register() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'AI Forms', 'rapid-ai-forms' ),
			__( 'AI Forms', 'rapid-ai-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_app_root' ),
			'dashicons-feedback',
			30
		);

		// Rename the auto-generated submenu duplicate from "AI Forms" to "Forms".
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Forms', 'rapid-ai-forms' ),
			__( 'Forms', 'rapid-ai-forms' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_app_root' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Submissions', 'rapid-ai-forms' ),
			__( 'Submissions', 'rapid-ai-forms' ),
			'manage_options',
			self::SUBMISSIONS_SLUG,
			array( $this, 'render_app_root' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'AI Forms Settings', 'rapid-ai-forms' ),
			__( 'Settings', 'rapid-ai-forms' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_app_root' )
		);
	}

	public function render_app_root() {
		echo '<div class="wrap"><div id="rapid-ai-forms-admin-root"></div></div>';
	}

	public function enqueue_assets( $hook_suffix ) {
		// Reading the ?page= slug from an admin URL — no form data, no nonce needed.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( ! in_array( $page, array( self::MENU_SLUG, self::SUBMISSIONS_SLUG, self::SETTINGS_SLUG ), true ) ) {
			return;
		}
		unset( $hook_suffix );

		$asset_file = RAPID_AI_FORMS_PATH . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-data', 'wp-notices' ),
				'version'      => RAPID_AI_FORMS_VERSION,
			);

		wp_enqueue_script(
			'rapid-ai-forms-admin',
			RAPID_AI_FORMS_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'rapid-ai-forms-admin',
			'RAPID_AI_FORMS_ADMIN',
			array(
				'restUrl'        => esc_url_raw( rest_url( 'rapid-ai-forms/v1/' ) ),
				'nonce'          => wp_create_nonce( 'wp_rest' ),
				'adminUrl'       => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
				'submissionsUrl' => admin_url( 'admin.php?page=' . self::SUBMISSIONS_SLUG ),
				'settingsUrl'    => admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ),
				'pluginUrl'      => RAPID_AI_FORMS_URL,
				'previewUrl'     => esc_url_raw( home_url( '/' ) ),
				'page'           => $page,
			)
		);

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'rapid-ai-forms-admin',
			RAPID_AI_FORMS_URL . 'build/admin.css',
			array( 'wp-components' ),
			$asset['version']
		);

		wp_set_script_translations( 'rapid-ai-forms-admin', 'rapid-ai-forms' );
	}
}
