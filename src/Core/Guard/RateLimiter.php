<?php
/**
 * Questions per visitor per hour.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Fixed one-hour window per visitor key. The key is a salted hash of the
 * visitor's IP address made by the caller; the address itself is never stored.
 */
final class RateLimiter {

	/**
	 * Constructor.
	 *
	 * @param CounterStore $store    Counter storage.
	 * @param int          $per_hour Questions allowed per visitor per hour; 0 disables the limit.
	 */
	public function __construct( private CounterStore $store, private int $per_hour ) {
	}

	/**
	 * Counts a question and says whether it is allowed.
	 *
	 * @param string $visitor_key Hashed visitor key.
	 * @param int    $now         Unix time.
	 * @return bool
	 */
	public function allow( string $visitor_key, int $now ): bool {
		if ( $this->per_hour <= 0 ) {
			return true;
		}
		$window = intdiv( $now, 3600 );
		$count  = $this->store->increment( 'rl_' . substr( $visitor_key, 0, 32 ) . '_' . $window, 3600 );
		return $count <= $this->per_hour;
	}
}
