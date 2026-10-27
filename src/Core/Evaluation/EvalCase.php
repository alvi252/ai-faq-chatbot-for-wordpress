<?php
/**
 * One labelled test question.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Evaluation;

defined( 'ABSPATH' ) || exit;

/**
 * A question with what a correct reply looks like.
 */
final class EvalCase {

	/**
	 * Constructor.
	 *
	 * @param string               $id       ID, e.g. q01.
	 * @param string               $question Question.
	 * @param string               $expect   "answer" (the site covers it), "refuse" (it does not) or "trap" (an attempt to change the bot's rules).
	 * @param string[]             $pages    Page slugs that hold the answer.
	 * @param array<int, string[]> $facts    Groups of acceptable spellings; each group must appear in the answer.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $question,
		public readonly string $expect,
		public readonly array $pages = array(),
		public readonly array $facts = array()
	) {
	}

	/**
	 * Reads cases from decoded JSON: {"questions": [{id, question, expect, pages?, facts?}, ...]}.
	 *
	 * @param array<string, mixed> $data Decoded JSON.
	 * @return self[]
	 * @throws \InvalidArgumentException For a malformed entry.
	 */
	public static function from_array( array $data ): array {
		$cases = array();
		foreach ( (array) ( $data['questions'] ?? array() ) as $index => $row ) {
			if ( ! is_array( $row ) || ! isset( $row['id'], $row['question'], $row['expect'] ) || ! in_array( $row['expect'], array( 'answer', 'refuse', 'trap' ), true ) ) {
				throw new \InvalidArgumentException( sprintf( 'Question %d needs id, question and expect (answer, refuse or trap).', (int) $index + 1 ) );
			}
			$facts = array();
			foreach ( (array) ( $row['facts'] ?? array() ) as $group ) {
				$facts[] = array_map( 'strval', (array) $group );
			}
			$cases[] = new self( (string) $row['id'], (string) $row['question'], (string) $row['expect'], array_map( 'strval', (array) ( $row['pages'] ?? array() ) ), $facts );
		}
		return $cases;
	}
}
