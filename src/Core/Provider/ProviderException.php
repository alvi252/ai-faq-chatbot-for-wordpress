<?php
/**
 * Exception for a failed API call.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * The API did not return a usable answer. Carries a machine-readable reason
 * and the token usage when the API answered (tokens are billed even when the
 * answer turns out to be unusable). The message is plain English for logs.
 */
final class ProviderException extends \RuntimeException {

	public const AUTH             = 'auth';
	public const RATE_LIMITED     = 'rate_limited';
	public const SERVER           = 'server';
	public const BAD_REQUEST      = 'bad_request';
	public const TIMEOUT          = 'timeout';
	public const NETWORK          = 'network';
	public const REFUSED          = 'refused';
	public const TRUNCATED        = 'truncated';
	public const INVALID_RESPONSE = 'invalid_response';

	private const RETRYABLE = array( self::RATE_LIMITED, self::SERVER, self::TIMEOUT, self::NETWORK );

	/**
	 * Answer whose tokens were spent, when there was one.
	 *
	 * @var ProviderResult|null
	 */
	private ?ProviderResult $usage = null;

	/**
	 * Constructor.
	 *
	 * @param string               $reason  Machine-readable reason (one of the constants).
	 * @param string               $message English description for logs.
	 * @param array<string, mixed> $context Values that describe the problem.
	 */
	public function __construct( private string $reason, string $message, private array $context = array() ) {
		parent::__construct( $message );
	}

	/**
	 * Returns the machine-readable reason.
	 *
	 * @return string
	 */
	public function get_reason(): string {
		return $this->reason;
	}

	/**
	 * Returns values that describe the problem.
	 *
	 * @return array<string, mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}

	/**
	 * Whether trying again later may succeed.
	 *
	 * @return bool
	 */
	public function is_retryable(): bool {
		return in_array( $this->reason, self::RETRYABLE, true );
	}

	/**
	 * Attaches the answer whose tokens were spent.
	 *
	 * @param ProviderResult $usage Answer.
	 * @return self
	 */
	public function with_usage( ProviderResult $usage ): self {
		$this->usage = $usage;
		return $this;
	}

	/**
	 * Returns the answer whose tokens were spent, if any.
	 *
	 * @return ProviderResult|null
	 */
	public function get_usage(): ?ProviderResult {
		return $this->usage;
	}

	/**
	 * Builds the exception for an HTTP error status.
	 *
	 * @param int      $status      HTTP status.
	 * @param string   $message     Error message from the API, if any.
	 * @param int|null $retry_after Seconds from the retry-after header.
	 * @return self
	 */
	public static function from_status( int $status, string $message, ?int $retry_after = null ): self {
		if ( 401 === $status || 403 === $status ) {
			$reason = self::AUTH;
		} elseif ( 429 === $status ) {
			$reason = self::RATE_LIMITED;
		} elseif ( 408 === $status || $status >= 500 ) {
			$reason = self::SERVER;
		} else {
			$reason = self::BAD_REQUEST;
		}

		return new self(
			$reason,
			sprintf( 'HTTP %d: %s', $status, '' === $message ? 'no error message' : $message ),
			array(
				'status'      => $status,
				'message'     => $message,
				'retry_after' => $retry_after,
			)
		);
	}

	/**
	 * The request timed out.
	 *
	 * @param string $message Error from the HTTP library.
	 * @return self
	 */
	public static function timeout( string $message ): self {
		return new self( self::TIMEOUT, 'Request timed out: ' . $message, array( 'message' => $message ) );
	}

	/**
	 * The request failed before a response arrived.
	 *
	 * @param string $message Error from the HTTP library.
	 * @return self
	 */
	public static function network( string $message ): self {
		return new self( self::NETWORK, 'Request failed: ' . $message, array( 'message' => $message ) );
	}

	/**
	 * The model declined the request.
	 *
	 * @param string $message Refusal text or category.
	 * @return self
	 */
	public static function refused( string $message ): self {
		return new self( self::REFUSED, 'The model declined the request: ' . $message, array( 'message' => $message ) );
	}

	/**
	 * The answer was cut off by the token limit.
	 *
	 * @param string $detail API's stop reason.
	 * @return self
	 */
	public static function truncated( string $detail ): self {
		return new self( self::TRUNCATED, 'The answer was cut off: ' . $detail, array( 'message' => $detail ) );
	}

	/**
	 * The response body is not in the documented shape.
	 *
	 * @param string $detail What was wrong.
	 * @return self
	 */
	public static function invalid_response( string $detail ): self {
		return new self( self::INVALID_RESPONSE, 'Unexpected response: ' . $detail, array( 'message' => $detail ) );
	}
}
