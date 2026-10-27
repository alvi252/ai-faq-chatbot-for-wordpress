<?php
/**
 * Shared provider helpers.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Provider;

use AIFAQ\Core\Http\HttpResponse;

defined( 'ABSPATH' ) || exit;

/**
 * JSON encoding/decoding and HTTP error mapping shared by the OpenAI calls
 * (answers and embeddings).
 */
abstract class AbstractProvider {

	/**
	 * Encodes a request body.
	 *
	 * @param array<string, mixed> $body Body.
	 * @return string
	 */
	protected static function encode( array $body ): string {
		return (string) json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
	}

	/**
	 * Decodes a successful response body, or throws for an error status.
	 *
	 * @param HttpResponse $response Response.
	 * @return array<string, mixed>
	 * @throws ProviderException For an error status or a body that is not a JSON object.
	 */
	protected static function decode( HttpResponse $response ): array {
		$data = json_decode( $response->body, true );

		if ( $response->status < 200 || $response->status >= 300 ) {
			throw ProviderException::from_status( $response->status, self::error_message( $data ), self::retry_after( $response ) );
		}

		if ( ! is_array( $data ) ) {
			throw ProviderException::invalid_response( 'the body is not a JSON object' );
		}
		return $data;
	}

	/**
	 * Reads the error message from an OpenAI error body: {"error": {"message": "...", "type": "..."}}.
	 *
	 * @param mixed $data Decoded body.
	 * @return string
	 */
	private static function error_message( mixed $data ): string {
		if ( is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ) {
			$message = self::mask_secrets( (string) ( $data['error']['message'] ?? '' ) );
			$type    = (string) ( $data['error']['type'] ?? '' );
			return '' !== $type && '' !== $message ? $type . ': ' . $message : $message . $type;
		}
		return '';
	}

	/**
	 * Masks API-key-like strings. Providers echo (partly masked) keys in
	 * authentication errors, and error messages end up in the usage log.
	 *
	 * @param string $message Provider message.
	 * @return string
	 */
	public static function mask_secrets( string $message ): string {
		// Written as s[k] so the pattern itself never looks like a key to secret scanners.
		return (string) preg_replace( '/\bs[k]-[A-Za-z0-9*_\-]{4,}/', '[api key]', $message );
	}

	/**
	 * Reads the retry-after header in seconds (the HTTP-date form is ignored).
	 *
	 * @param HttpResponse $response Response.
	 * @return int|null
	 */
	private static function retry_after( HttpResponse $response ): ?int {
		$value = trim( $response->header( 'retry-after' ) );
		return '' !== $value && ctype_digit( $value ) ? (int) $value : null;
	}

	/**
	 * Reads a non-negative integer from a nested array.
	 *
	 * @param array<string, mixed> $data Data.
	 * @param string               $key  Key.
	 * @return int
	 */
	protected static function int( array $data, string $key ): int {
		return isset( $data[ $key ] ) && is_numeric( $data[ $key ] ) ? max( 0, (int) $data[ $key ] ) : 0;
	}
}
