<?php
/**
 * Scores replies against labelled questions.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Evaluation;

use AIFAQ\Core\Answer\AnswerResult;
use AIFAQ\Core\Search\SearchHit;

defined( 'ABSPATH' ) || exit;

/**
 * Checks, per question: was the reply the right kind (answer or refusal), did
 * search find the page that holds the answer, did the answer cite that page,
 * and does it contain the expected facts.
 */
final class EvalScorer {

	/**
	 * Constructor.
	 *
	 * @param callable(int): string $slug_of Post ID to page slug.
	 */
	public function __construct( private $slug_of ) {
	}

	/**
	 * Scores one reply.
	 *
	 * @param EvalCase     $eval_case Question.
	 * @param AnswerResult $result    Reply.
	 * @return array<string, mixed>
	 */
	public function score( EvalCase $eval_case, AnswerResult $result ): array {
		$answered   = AnswerResult::ANSWERED === $result->status;
		$retrieved  = $this->slugs( $result->retrieved );
		$cited      = $this->slugs( $result->sources );
		$expects    = 'answer' === $eval_case->expect;
		$facts_seen = 0;
		foreach ( $eval_case->facts as $group ) {
			$facts_seen += self::contains_any( $result->answer, $group ) ? 1 : 0;
		}

		return array(
			'id'          => $eval_case->id,
			'question'    => $eval_case->question,
			'expect'      => $eval_case->expect,
			'status'      => $result->status,
			'reason'      => $result->reason,
			'correct'     => $expects ? $answered : AnswerResult::REFUSED === $result->status,
			'retrieved'   => $expects ? array() !== array_intersect( $eval_case->pages, $retrieved ) : null,
			'cited'       => $expects ? array() !== array_intersect( $eval_case->pages, $cited ) : null,
			'facts_found' => $facts_seen,
			'facts_total' => count( $eval_case->facts ),
			'top_page'    => $retrieved[0] ?? '',
			'similarity'  => round( $result->best_similarity, 3 ),
			'latency_ms'  => $result->latency_ms,
			'cost'        => $result->cost(),
			'answer'      => $result->answer,
		);
	}

	/**
	 * Page slugs of hits, in order, without repeats.
	 *
	 * @param SearchHit[] $hits Hits.
	 * @return string[]
	 */
	private function slugs( array $hits ): array {
		$slugs = array();
		foreach ( $hits as $hit ) {
			$slugs[] = (string) ( $this->slug_of )( $hit->passage->post_id );
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Whether any spelling appears, ignoring case and the kind of dash.
	 *
	 * @param string   $text      Text.
	 * @param string[] $spellings Acceptable spellings.
	 * @return bool
	 */
	public static function contains_any( string $text, array $spellings ): bool {
		$normalize = static fn( string $s ): string => mb_strtolower( str_replace( array( "\u{2013}", "\u{2014}", "\u{2011}" ), '-', $s ) );
		$haystack  = $normalize( $text );
		foreach ( $spellings as $spelling ) {
			if ( '' !== $spelling && str_contains( $haystack, $normalize( $spelling ) ) ) {
				return true;
			}
		}
		return false;
	}
}
