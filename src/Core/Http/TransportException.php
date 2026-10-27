<?php
/**
 * Exception for a request that got no response.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * No HTTP response was received.
 */
final class TransportException extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $message    Error from the HTTP library.
	 * @param bool   $is_timeout Whether the request timed out.
	 */
	public function __construct( string $message, private bool $is_timeout = false ) {
		parent::__construct( $message );
	}

	/**
	 * Whether the request timed out.
	 *
	 * @return bool
	 */
	public function is_timeout(): bool {
		return $this->is_timeout;
	}
}
