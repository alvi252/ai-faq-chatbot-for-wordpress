<?php
/**
 * Counter storage.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Guard;

defined( 'ABSPATH' ) || exit;

/**
 * Expiring counters. The plugin uses transients; tests use an array.
 */
interface CounterStore {

	/**
	 * Adds one and returns the new value; a missing counter starts at 1 and expires after $ttl seconds.
	 *
	 * @param string $key Key.
	 * @param int    $ttl Lifetime in seconds.
	 * @return int
	 */
	public function increment( string $key, int $ttl ): int;
}
