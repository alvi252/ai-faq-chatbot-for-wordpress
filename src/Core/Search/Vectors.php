<?php
/**
 * Vector helpers.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Packs vectors for the database and compares them.
 */
final class Vectors {

	/**
	 * Packs a vector as little-endian 32-bit floats (2 KB for 512 dimensions).
	 *
	 * @param float[] $vector Vector.
	 * @return string Binary string.
	 */
	public static function pack( array $vector ): string {
		return pack( 'g*', ...array_map( 'floatval', array_values( $vector ) ) );
	}

	/**
	 * Unpacks a vector packed by pack().
	 *
	 * @param string $binary Binary string.
	 * @return float[]
	 */
	public static function unpack( string $binary ): array {
		if ( '' === $binary || 0 !== strlen( $binary ) % 4 ) {
			return array();
		}
		$values = unpack( 'g*', $binary );
		return false === $values ? array() : array_values( $values );
	}

	/**
	 * Dot product; for unit-length vectors this is the cosine similarity.
	 *
	 * @param float[] $a Vector.
	 * @param float[] $b Vector of the same length.
	 * @return float
	 */
	public static function dot( array $a, array $b ): float {
		$sum   = 0.0;
		$count = min( count( $a ), count( $b ) );
		for ( $i = 0; $i < $count; $i++ ) {
			$sum += $a[ $i ] * $b[ $i ];
		}
		return $sum;
	}
}
