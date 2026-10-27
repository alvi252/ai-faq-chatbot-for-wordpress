<?php
/**
 * BM25 keyword scoring.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Okapi BM25 over a small corpus held in memory, with the same parameters as
 * the rank_bm25 Python package used in docs-assistant-rag (k1 1.5, b 0.75,
 * negative IDF raised to a quarter of the average IDF).
 */
final class Bm25 {

	private const K1      = 1.5;
	private const B       = 0.75;
	private const EPSILON = 0.25;

	/**
	 * Term frequencies per document.
	 *
	 * @var array<int, array<string, int>>
	 */
	private array $frequencies = array();

	/**
	 * Document lengths in tokens.
	 *
	 * @var int[]
	 */
	private array $lengths = array();

	/**
	 * IDF per term.
	 *
	 * @var array<string, float>
	 */
	private array $idf = array();

	/**
	 * Average document length.
	 *
	 * @var float
	 */
	private float $average_length = 0.0;

	/**
	 * Constructor.
	 *
	 * @param array<int, string[]> $corpus Token lists, one per document.
	 */
	public function __construct( array $corpus ) {
		$document_frequency = array();
		foreach ( array_values( $corpus ) as $index => $tokens ) {
			$frequencies                 = array_count_values( $tokens );
			$this->frequencies[ $index ] = $frequencies;
			$this->lengths[ $index ]     = count( $tokens );
			foreach ( array_keys( $frequencies ) as $term ) {
				$document_frequency[ $term ] = ( $document_frequency[ $term ] ?? 0 ) + 1;
			}
		}

		$total = count( $this->lengths );
		if ( 0 === $total ) {
			return;
		}
		$this->average_length = array_sum( $this->lengths ) / $total;

		$negative = array();
		$sum      = 0.0;
		foreach ( $document_frequency as $term => $count ) {
			$idf                         = log( ( $total - $count + 0.5 ) / ( $count + 0.5 ) );
			$this->idf[ (string) $term ] = $idf;
			$sum                        += $idf;
			if ( $idf < 0 ) {
				$negative[] = (string) $term;
			}
		}
		$floor = self::EPSILON * ( $sum / max( 1, count( $this->idf ) ) );
		foreach ( $negative as $term ) {
			$this->idf[ $term ] = $floor;
		}
	}

	/**
	 * Scores every document against a query.
	 *
	 * @param string[] $query Query tokens.
	 * @return float[] Score per document, in corpus order.
	 */
	public function scores( array $query ): array {
		$scores = array();
		foreach ( $this->frequencies as $index => $frequencies ) {
			$score = 0.0;
			$norm  = self::K1 * ( 1 - self::B + self::B * $this->lengths[ $index ] / max( 1.0, $this->average_length ) );
			foreach ( $query as $term ) {
				$tf = $frequencies[ $term ] ?? 0;
				if ( 0 === $tf ) {
					continue;
				}
				$score += ( $this->idf[ $term ] ?? 0.0 ) * ( $tf * ( self::K1 + 1 ) ) / ( $tf + $norm );
			}
			$scores[ $index ] = $score;
		}
		return $scores;
	}
}
