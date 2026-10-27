<?php
/**
 * Outgoing HTTP request.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * A provider request, independent of the HTTP library that sends it.
 */
final class HttpRequest {

	/**
	 * Constructor.
	 *
	 * @param string                $url     Absolute URL.
	 * @param array<string, string> $headers Request headers.
	 * @param string                $body    Request body (JSON).
	 * @param int                   $timeout Timeout in seconds.
	 * @param string                $method  HTTP method.
	 */
	public function __construct(
		public readonly string $url,
		public readonly array $headers,
		public readonly string $body,
		public readonly int $timeout = 60,
		public readonly string $method = 'POST'
	) {
	}

	/**
	 * Returns the request with secret headers masked, for logs and test output.
	 *
	 * @return array{url: string, headers: array<string, string>, body: string}
	 */
	public function redacted(): array {
		$headers = array();
		foreach ( $this->headers as $name => $value ) {
			$secret           = in_array( strtolower( $name ), array( 'authorization', 'x-api-key' ), true );
			$headers[ $name ] = $secret ? '[redacted]' : $value;
		}
		return array(
			'url'     => $this->url,
			'headers' => $headers,
			'body'    => $this->body,
		);
	}
}
