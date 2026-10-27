<?php
/**
 * Measures search time and memory for synthetic indexes of growing size.
 * Development helper only; not part of the plugin package.
 *
 *   docker run --rm -v "$PWD":/app -w /app php:8.2-cli php bin/bench-search.php
 *
 * @package AIFAQ
 */

define( 'ABSPATH', __DIR__ . '/' );
require dirname( __DIR__ ) . '/vendor/autoload.php';

use AIFAQ\Core\Content\Passage;
use AIFAQ\Core\Search\HybridSearch;
use AIFAQ\Core\Search\Vectors;

mt_srand( 42 );
$aifaq_dims  = 512;
$aifaq_words = explode( ' ', 'delivery return refund order parcel warranty filter fountain feeder food dog cat kitten puppy price stock payment transfer card subscription autoship pause skip invoice gift shop lyon express germany spain france portugal' );

/**
 * Random unit vector.
 *
 * @param int $dims Dimensions.
 * @return float[]
 */
function aifaq_random_vector( int $dims ): array {
	$v = array();
	for ( $i = 0; $i < $dims; $i++ ) {
		$v[] = mt_rand( -1000, 1000 ) / 1000;
	}
	$n = sqrt( array_sum( array_map( static fn( $x ) => $x * $x, $v ) ) );
	return array_map( static fn( $x ) => $x / $n, $v );
}

foreach ( array( 100, 500, 1000, 2000, 5000 ) as $aifaq_count ) {
	$aifaq_passages = array();
	for ( $i = 0; $i < $aifaq_count; $i++ ) {
		$aifaq_text = '';
		for ( $w = 0; $w < 120; $w++ ) {
			$aifaq_text .= $aifaq_words[ mt_rand( 0, count( $aifaq_words ) - 1 ) ] . ' ';
		}
		// Round-trip through the database format, as the plugin does on every question.
		$aifaq_passages[] = new Passage( $i, 'Page ' . $i, 'https://x.test/' . $i, 'Heading', $aifaq_text, 0, Vectors::unpack( Vectors::pack( aifaq_random_vector( $aifaq_dims ) ) ) );
	}
	$aifaq_query = aifaq_random_vector( $aifaq_dims );

	$aifaq_search = new HybridSearch( 5, 0.0 );
	$aifaq_start  = microtime( true );
	$aifaq_runs   = 3;
	for ( $r = 0; $r < $aifaq_runs; $r++ ) {
		$aifaq_search->search( $aifaq_query, 'how long does delivery to germany take', $aifaq_passages );
	}
	printf( "%5d passages: %6.0f ms per search, peak memory %4.0f MB\n", $aifaq_count, ( microtime( true ) - $aifaq_start ) / $aifaq_runs * 1000, memory_get_peak_usage( true ) / 1048576 );
}
