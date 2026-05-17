<?php
/**
 * PSR-4-ish autoloader for the plugin.
 *
 * Maps `WP_AI_Forms\Some\Thing` -> `includes/some/class-thing.php`
 * Maps `WP_AI_Forms\Some\Thing_Interface` -> `includes/some/interface-thing.php`
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms;

defined( 'ABSPATH' ) || exit;

class Autoloader {
	const NAMESPACE_PREFIX = 'WP_AI_Forms\\';

	public static function register() {
		spl_autoload_register( [ __CLASS__, 'load' ] );
	}

	public static function load( $class ) {
		if ( strpos( $class, self::NAMESPACE_PREFIX ) !== 0 ) {
			return;
		}

		$relative = substr( $class, strlen( self::NAMESPACE_PREFIX ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );

		$dir = WP_AI_FORMS_PATH . 'includes/';
		foreach ( $parts as $segment ) {
			$dir .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $short ) );

		$candidates = [
			$dir . 'class-' . $slug . '.php',
			$dir . 'interface-' . preg_replace( '/-interface$/', '', $slug ) . '.php',
			$dir . 'trait-' . $slug . '.php',
		];

		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
