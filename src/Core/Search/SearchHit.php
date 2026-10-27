<?php
/**
 * One search hit.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Search;

use AIFAQ\Core\Content\Passage;

defined( 'ABSPATH' ) || exit;

/**
 * A passage that passed the relevance gate, with its scores.
 */
final class SearchHit {

	/**
	 * Constructor.
	 *
	 * @param Passage  $passage      Passage.
	 * @param float    $similarity   Cosine similarity to the question.
	 * @param float    $score        Fused score.
	 * @param int|null $vector_rank  Rank in the vector ranking, from 1.
	 * @param int|null $keyword_rank Rank in the keyword ranking, from 1.
	 */
	public function __construct(
		public readonly Passage $passage,
		public readonly float $similarity,
		public readonly float $score,
		public readonly ?int $vector_rank,
		public readonly ?int $keyword_rank
	) {
	}
}
