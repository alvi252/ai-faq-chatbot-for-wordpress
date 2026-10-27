<?php
/**
 * Minimal PSR-4 autoloader, so the plugin works without a Composer install.
 *
 * @package AIFAQ
 */

namespace AIFAQ;

defined( 'ABSPATH' ) || exit;

/**
 * Maps the AIFAQ\ namespace onto the src/ directory.
 */
final class Autoloader {

	/**
	 * Registers the autoloader.
	 *
	 * @param string $base_dir Absolute path of the src/ directory, with a trailing slash.
	 */
	public static function register( string $base_dir ): void {
		spl_autoload_register(
			static function ( string $class_name ) use ( $base_dir ): void {
				$prefix = __NAMESPACE__ . '\\';
				if ( 0 !== strpos( $class_name, $prefix ) ) {
					return;
				}

				$relative = substr( $class_name, strlen( $prefix ) );
				$file     = $base_dir . str_replace( '\\', '/', $relative ) . '.php';

				if ( is_readable( $file ) ) {
					require $file;
				}
			}
		);
	}
}
