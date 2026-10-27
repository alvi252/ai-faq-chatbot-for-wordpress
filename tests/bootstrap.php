<?php
/**
 * Unit test bootstrap.
 *
 * The tested classes (src/Core) are WordPress-independent. Only the ABSPATH
 * constant is defined, so their "defined( 'ABSPATH' ) || exit;" guard
 * passes; a call to any WordPress function would fail. No test touches the
 * network: HTTP goes through tests/Support/FakeTransport.
 *
 * @package AIFAQ
 */

define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__ ) . '/vendor/autoload.php';

if ( function_exists( 'add_action' ) || function_exists( 'wp_remote_post' ) ) {
	throw new RuntimeException( 'Unit tests must run without WordPress.' );
}
