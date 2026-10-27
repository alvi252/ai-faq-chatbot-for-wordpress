<?php
/**
 * Search result.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Search;

defined( 'ABSPATH' ) || exit;

/**
 * Hits in rank order and the best similarity in the whole index (before the gate).
 */
final class SearchResult {

	/**
	 * Constructor.
	 *
	 * @param SearchHit[] $hits            Hits, best first.
	 * @param float       $best_similarity Highest similarity of any passage.
	 */
	public function __construct(
		public readonly array $hits,
		public readonly float $best_similarity
	) {
	}
}
