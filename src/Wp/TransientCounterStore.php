<?php
/**
 * Counters in transients.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Guard\CounterStore;

defined( 'ABSPATH' ) || exit;

/**
 * Expiring counters stored as transients (in the object cache when the site has one).
 * Not atomic: two requests in the same instant may both read the old value,
 * which lets a visitor slightly exceed the hourly limit. Good enough for a
 * cost guard; the daily budget is the hard stop.
 */
final class TransientCounterStore implements CounterStore {

	/**
	 * Adds one and returns the new value.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Lifetime in seconds.
	 * @return int
	 */
	public function increment( string $key, int $ttl ): int {
		$name  = 'aifaq_' . md5( $key );
		$count = (int) get_transient( $name ) + 1;
		set_transient( $name, $count, $ttl );
		return $count;
	}
}
