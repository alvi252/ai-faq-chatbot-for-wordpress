<?php
/**
 * Vector search plus keyword search.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Search;

use AIFAQ\Core\Content\Passage;

defined( 'ABSPATH' ) || exit;

/**
 * Ranks passages by embedding similarity and by BM25, fuses the two rankings
 * with reciprocal rank fusion, and keeps only passages whose similarity passes
 * the relevance gate. Keyword matches can re-rank relevant passages but cannot
 * pull in a passage the embedding finds unrelated. Same design as our
 * docs-assistant-rag demo, in plain PHP so the plugin needs no extra server.
 */
final class HybridSearch {

	public const RRF_K = 60;

	/**
	 * Constructor.
	 *
	 * @param int   $top_k          Passages returned.
	 * @param float $min_similarity Relevance gate (cosine similarity).
	 * @param int   $pool           Candidates taken from each ranking before fusion.
	 */
	public function __construct(
		private int $top_k = 5,
		private float $min_similarity = 0.30,
		private int $pool = 20
	) {
	}

	/**
	 * Searches.
	 *
	 * @param float[]   $query_vector Unit-length embedding of the question.
	 * @param string    $query_text   Question text, for keyword search.
	 * @param Passage[] $passages     Passages with vectors.
	 * @return SearchResult
	 */
	public function search( array $query_vector, string $query_text, array $passages ): SearchResult {
		$passages = array_values( array_filter( $passages, static fn( Passage $p ): bool => array() !== $p->vector ) );
		if ( array() === $passages ) {
			return new SearchResult( array(), 0.0 );
		}

		$similarity = array();
		foreach ( $passages as $index => $passage ) {
			$similarity[ $index ] = Vectors::dot( $query_vector, $passage->vector );
		}
		$best = max( $similarity );

		$by_vector = $similarity;
		arsort( $by_vector );
		$vector_ranking = array_slice( array_keys( $by_vector ), 0, $this->pool );

		$keyword_ranking = array();
		$query_tokens    = Tokenizer::tokens( $query_text );
		if ( array() !== $query_tokens ) {
			$corpus = array();
			foreach ( $passages as $passage ) {
				$corpus[] = Tokenizer::tokens( $passage->title . ' ' . $passage->heading . ' ' . $passage->text );
			}
			$by_keyword = ( new Bm25( $corpus ) )->scores( $query_tokens );
			arsort( $by_keyword );
			foreach ( $by_keyword as $index => $score ) {
				if ( $score <= 0 || count( $keyword_ranking ) >= $this->pool ) {
					break;
				}
				$keyword_ranking[] = $index;
			}
		}

		$fused      = self::reciprocal_rank_fusion( array( $vector_ranking, $keyword_ranking ) );
		$candidates = array_filter( array_keys( $fused ), fn( int $i ): bool => $similarity[ $i ] >= $this->min_similarity );
		usort(
			$candidates,
			static fn( int $a, int $b ): int => array( $fused[ $b ], $similarity[ $b ] ) <=> array( $fused[ $a ], $similarity[ $a ] )
		);

		$vector_rank  = array_flip( $vector_ranking );
		$keyword_rank = array_flip( $keyword_ranking );
		$hits         = array();
		foreach ( array_slice( $candidates, 0, $this->top_k ) as $index ) {
			$hits[] = new SearchHit(
				$passages[ $index ],
				$similarity[ $index ],
				$fused[ $index ],
				isset( $vector_rank[ $index ] ) ? $vector_rank[ $index ] + 1 : null,
				isset( $keyword_rank[ $index ] ) ? $keyword_rank[ $index ] + 1 : null
			);
		}

		return new SearchResult( $hits, $best );
	}

	/**
	 * Reciprocal rank fusion: score(item) = sum over rankings of 1 / (k + rank).
	 *
	 * @param array<int, int[]> $rankings Ranked item lists, best first.
	 * @param int               $k        Damping constant.
	 * @return array<int, float> Score per item.
	 */
	public static function reciprocal_rank_fusion( array $rankings, int $k = self::RRF_K ): array {
		$scores = array();
		foreach ( $rankings as $ranking ) {
			foreach ( array_values( $ranking ) as $position => $item ) {
				$scores[ $item ] = ( $scores[ $item ] ?? 0.0 ) + 1.0 / ( $k + $position + 1 );
			}
		}
		return $scores;
	}
}
