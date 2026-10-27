<?php
/**
 * Test-run report.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Evaluation;

defined( 'ABSPATH' ) || exit;

/**
 * Totals and a per-question table in Markdown.
 */
final class EvalReport {

	/**
	 * Totals over scored rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows from EvalScorer::score().
	 * @return array<string, mixed>
	 */
	public static function totals( array $rows ): array {
		$by = static fn( string $expect ): array => array_values( array_filter( $rows, static fn( array $r ): bool => $expect === $r['expect'] ) );

		$answerable = $by( 'answer' );
		$refuse     = $by( 'refuse' );
		$traps      = $by( 'trap' );
		$latencies  = array_map( static fn( array $r ): int => (int) $r['latency_ms'], $rows );
		sort( $latencies );
		$costs   = array_map( static fn( array $r ) => $r['cost'], $rows );
		$unknown = in_array( null, $costs, true );

		$count = static fn( array $set, string $key ): int => count( array_filter( $set, static fn( array $r ): bool => true === $r[ $key ] ) );

		return array(
			'questions'           => count( $rows ),
			'answerable'          => count( $answerable ),
			'answered'            => $count( $answerable, 'correct' ),
			'retrieved'           => $count( $answerable, 'retrieved' ),
			'cited'               => $count( $answerable, 'cited' ),
			'facts_found'         => array_sum( array_column( $answerable, 'facts_found' ) ),
			'facts_total'         => array_sum( array_column( $answerable, 'facts_total' ) ),
			'not_covered'         => count( $refuse ),
			'refused_not_covered' => $count( $refuse, 'correct' ),
			'traps'               => count( $traps ),
			'traps_held'          => $count( $traps, 'correct' ),
			'latency_median_ms'   => array() === $latencies ? 0 : $latencies[ intdiv( count( $latencies ), 2 ) ],
			'latency_max_ms'      => array() === $latencies ? 0 : max( $latencies ),
			'cost'                => $unknown ? null : round( array_sum( array_map( 'floatval', $costs ) ), 6 ),
		);
	}

	/**
	 * Markdown report.
	 *
	 * @param array<int, array<string, mixed>> $rows   Scored rows.
	 * @param string                           $header Line describing the run (date, models, settings).
	 * @return string
	 */
	public static function markdown( array $rows, string $header ): string {
		$t   = self::totals( $rows );
		$out = "# Test run\n\n" . $header . "\n\n";

		$out .= "| Check | Result |\n|---|---|\n";
		$out .= sprintf( "| Questions the site answers: answered | %d of %d |\n", $t['answered'], $t['answerable'] );
		$out .= sprintf( "| Page with the answer found by search | %d of %d |\n", $t['retrieved'], $t['answerable'] );
		$out .= sprintf( "| Page with the answer cited | %d of %d |\n", $t['cited'], $t['answerable'] );
		$out .= sprintf( "| Expected facts in the answers | %d of %d |\n", $t['facts_found'], $t['facts_total'] );
		$out .= sprintf( "| Questions the site does not cover: \"I don't know\" | %d of %d |\n", $t['refused_not_covered'], $t['not_covered'] );
		$out .= sprintf( "| Attempts to change the rules: refused | %d of %d |\n", $t['traps_held'], $t['traps'] );
		$out .= sprintf( "| Time per question, median / max | %.1f s / %.1f s |\n", $t['latency_median_ms'] / 1000, $t['latency_max_ms'] / 1000 );
		$out .= sprintf( "| Estimated cost of the run | %s |\n\n", null === $t['cost'] ? 'unknown (a model has no price)' : '$' . number_format( (float) $t['cost'], 4 ) );

		$out .= "| # | Question | Expected | Reply | Top page | Cited | Facts | Time |\n|---|---|---|---|---|---|---|---|\n";
		foreach ( $rows as $r ) {
			$reply = 'answered' === $r['status'] ? 'answered' : $r['status'] . ( '' !== $r['reason'] ? ' (' . $r['reason'] . ')' : '' );
			$out  .= sprintf(
				"| %s | %s | %s | %s%s | %s | %s | %s | %.1f s |\n",
				$r['id'],
				str_replace( '|', '/', $r['question'] ),
				$r['expect'],
				$r['correct'] ? '' : 'WRONG: ',
				$reply,
				'' === $r['top_page'] ? '-' : $r['top_page'],
				null === $r['cited'] ? '-' : ( $r['cited'] ? 'yes' : 'no' ),
				$r['facts_total'] > 0 ? $r['facts_found'] . '/' . $r['facts_total'] : '-',
				$r['latency_ms'] / 1000
			);
		}

		$out .= "\n## Replies\n\n";
		foreach ( $rows as $r ) {
			$out .= sprintf( "**%s.** %s\n\n> %s\n\n", $r['id'], $r['question'], str_replace( "\n", "\n> ", (string) $r['answer'] ) );
		}
		return $out;
	}
}
