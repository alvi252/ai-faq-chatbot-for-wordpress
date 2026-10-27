<?php
/**
 * HTTP response.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * A provider response, independent of the HTTP library that received it.
 */
final class HttpResponse {

	/**
	 * Response headers with lower-case names.
	 *
	 * @var array<string, string>
	 */
	public readonly array $headers;

	/**
	 * Constructor.
	 *
	 * @param int                   $status  HTTP status code.
	 * @param string                $body    Response body.
	 * @param array<string, string> $headers Response headers (any case).
	 */
	public function __construct(
		public readonly int $status,
		public readonly string $body,
		array $headers = array()
	) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
	}

	/**
	 * Returns a header value, or an empty string.
	 *
	 * @param string $name Header name, any case.
	 * @return string
	 */
	public function header( string $name ): string {
		return (string) ( $this->headers[ strtolower( $name ) ] ?? '' );
	}
}
