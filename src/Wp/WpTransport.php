<?php
/**
 * HTTP transport over the WordPress HTTP API.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Http\HttpRequest;
use AIFAQ\Core\Http\HttpResponse;
use AIFAQ\Core\Http\TransportException;
use AIFAQ\Core\Http\TransportInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Sends provider requests with wp_remote_post() and the request's timeout.
 */
final class WpTransport implements TransportInterface {

	/**
	 * Sends a request.
	 *
	 * @param HttpRequest $request Request.
	 * @return HttpResponse
	 * @throws TransportException When no response was received.
	 */
	public function send( HttpRequest $request ): HttpResponse {
		$response = wp_remote_post(
			$request->url,
			array(
				'method'      => $request->method,
				'timeout'     => $request->timeout,
				'redirection' => 0,
				'headers'     => $request->headers,
				'body'        => $request->body,
				'data_format' => 'body',
				'user-agent'  => 'AIFAQChatbot/' . AIFAQ_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			// cURL error 28 is a timeout; the Requests library words it "timed out".
			$timeout = false !== stripos( $message, 'timed out' ) || false !== strpos( $message, 'cURL error 28' );
			throw new TransportException( $message, $timeout );
		}

		$headers = array();
		$raw     = wp_remote_retrieve_headers( $response );
		$raw     = is_object( $raw ) && method_exists( $raw, 'getAll' ) ? $raw->getAll() : (array) $raw;
		foreach ( $raw as $name => $value ) {
			$headers[ (string) $name ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}

		return new HttpResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			$headers
		);
	}
}
