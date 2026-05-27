<?php
/**
 * Database schema installer.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Db;

defined( 'ABSPATH' ) || exit;

class Schema {
	const DB_VERSION = '1.0.3';

	public static function forms_table() {
		global $wpdb;
		return $wpdb->prefix . 'rapid_ai_forms';
	}

	public static function submissions_table() {
		global $wpdb;
		return $wpdb->prefix . 'rapid_ai_form_submissions';
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$forms           = self::forms_table();
		$submissions     = self::submissions_table();

		$sql = array();

		$sql[] = "CREATE TABLE {$forms} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			uuid VARCHAR(36) NOT NULL,
			title VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			form_schema LONGTEXT NOT NULL,
			settings LONGTEXT NOT NULL,
			ai_prompt LONGTEXT NULL,
			author_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid),
			KEY status (status),
			KEY author_id (author_id)
		) {$charset_collate};";

		$sql[] = "CREATE TABLE {$submissions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id BIGINT UNSIGNED NOT NULL,
			data LONGTEXT NOT NULL,
			meta LONGTEXT NULL,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql );

		update_option( 'rapid_ai_forms_db_version', self::DB_VERSION );
	}
}
