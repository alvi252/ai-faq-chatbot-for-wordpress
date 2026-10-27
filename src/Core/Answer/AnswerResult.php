<?php
/**
 * Result of one question.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Answer;

use AIFAQ\Core\Search\SearchHit;

defined( 'ABSPATH' ) || exit;

/**
 * What the visitor sees, why, and what it cost.
 */
final class AnswerResult {

	public const ANSWERED = 'answered';
	public const REFUSED  = 'refused';
	public const ERROR    = 'error';

	// Why the bot did not answer.
	public const NO_MATCH       = 'no_match';
	public const EMPTY_INDEX    = 'empty_index';
	public const MODEL_DECLINED = 'model_declined';
	public const NO_CITATION    = 'no_citation';

	/**
	 * Constructor.
	 *
	 * @param string                           $status          One of ANSWERED, REFUSED, ERROR.
	 * @param string                           $answer          Text shown to the visitor, with [n] markers when answered.
	 * @param SearchHit[]                      $sources         Cited passages, in [n] order.
	 * @param string                           $reason          Why not answered (constant or API error reason), empty when answered.
	 * @param SearchHit[]                      $retrieved       Passages sent to the model (or that would have been).
	 * @param float                            $best_similarity Highest similarity in the index.
	 * @param array<int, array<string, mixed>> $calls           API calls: kind, model, status, input_tokens, output_tokens, cost, error.
	 * @param int                              $latency_ms      Total time.
	 */
	public function __construct(
		public readonly string $status,
		public readonly string $answer,
		public readonly array $sources,
		public readonly string $reason,
		public readonly array $retrieved,
		public readonly float $best_similarity,
		public readonly array $calls,
		public readonly int $latency_ms
	) {
	}

	/**
	 * Estimated cost of all calls, or null when some call has no price.
	 *
	 * @return float|null
	 */
	public function cost(): ?float {
		$total = 0.0;
		foreach ( $this->calls as $call ) {
			if ( null === $call['cost'] && ( $call['input_tokens'] + $call['output_tokens'] ) > 0 ) {
				return null;
			}
			$total += (float) $call['cost'];
		}
		return round( $total, 9 );
	}
}
