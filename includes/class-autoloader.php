<?php
/**
 * PSR-4-ish autoloader for the plugin.
 *
 * Maps `Rapid_Ai_Forms\Some\Thing` -> `includes/some/class-thing.php`
 * Maps `Rapid_Ai_Forms\Some\Thing_Interface` -> `includes/some/interface-thing.php`
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms;

defined( 'ABSPATH' ) || exit;

class Autoloader {
	const NAMESPACE_PREFIX = 'Rapid_Ai_Forms\\';

	public static function register() {
		spl_autoload_register( array( __CLASS__, 'load' ) );
	}

	public static function load( $class_name ) {
		if ( strpos( $class_name, self::NAMESPACE_PREFIX ) !== 0 ) {
			return;
		}

		$relative = substr( $class_name, strlen( self::NAMESPACE_PREFIX ) );
		$parts    = explode( '\\', $relative );
		$short    = array_pop( $parts );

		$dir = RAPID_AI_FORMS_PATH . 'includes/';
		foreach ( $parts as $segment ) {
			$dir .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}

		$slug = strtolower( str_replace( '_', '-', $short ) );

		$candidates = array(
			$dir . 'class-' . $slug . '.php',
			$dir . 'interface-' . preg_replace( '/-interface$/', '', $slug ) . '.php',
			$dir . 'trait-' . $slug . '.php',
		);

		foreach ( $candidates as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
				return;
			}
		}
	}
}
