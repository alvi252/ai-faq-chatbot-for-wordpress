<?php
/**
 * HTTP transport contract.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Http;

defined( 'ABSPATH' ) || exit;

/**
 * Sends requests. The plugin uses wp_remote_post(); tests use a fake.
 */
interface TransportInterface {

	/**
	 * Sends a request and returns the response, whatever its status code.
	 *
	 * @param HttpRequest $request Request.
	 * @return HttpResponse
	 * @throws TransportException When no response was received (timeout, DNS, TLS, ...).
	 */
	public function send( HttpRequest $request ): HttpResponse;
}
