<?php
/**
 * WP-CLI commands.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Evaluation\EvalCase;
use AIFAQ\Core\Evaluation\EvalReport;
use AIFAQ\Core\Evaluation\EvalScorer;
use AIFAQ\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Index the site, ask a question and run a test set from the command line.
 */
final class Cli {

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param ContentSource     $content  Content.
	 * @param PassageRepository $passages Passages.
	 * @param Indexer           $indexer  Indexer.
	 * @param Chat              $chat     Chat.
	 */
	public function __construct(
		private Settings $settings,
		private ContentSource $content,
		private PassageRepository $passages,
		private Indexer $indexer,
		private Chat $chat
	) {
	}

	/**
	 * Indexes every page that belongs in the index.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Re-embed pages that did not change.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function index( array $args, array $assoc_args ): void {
		$ids     = $this->content->indexable_ids();
		$removed = $this->passages->delete_except( $ids );
		$counts  = array();
		$tokens  = 0;
		foreach ( $ids as $id ) {
			$outcome                      = $this->indexer->index_post( $id, ! empty( $assoc_args['force'] ) );
			$counts[ $outcome['status'] ] = ( $counts[ $outcome['status'] ] ?? 0 ) + 1;
			$tokens                      += $outcome['tokens'];
			\WP_CLI::log( sprintf( '%5d  %-9s %3d passages  %s', $id, $outcome['status'], $outcome['passages'], '' !== $outcome['error'] ? $outcome['error'] : get_the_title( $id ) ) );
		}
		\WP_CLI::success( sprintf( '%d pages: %s; %d removed; %d tokens embedded.', count( $ids ), wp_json_encode( $counts ), $removed, $tokens ) );
	}

	/**
	 * Asks one question.
	 *
	 * ## OPTIONS
	 *
	 * <question>
	 * : The question.
	 *
	 * [--passages]
	 * : Also list the passages search sent to the model, with their similarity.
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function ask( array $args, array $assoc_args = array() ): void {
		$reply  = $this->chat->ask( (string) ( $args[0] ?? '' ), null, false );
		$result = $reply['result'];
		\WP_CLI::log( (string) $reply['body']['status'] . ': ' . (string) $reply['body']['answer'] );
		foreach ( $reply['body']['sources'] as $source ) {
			\WP_CLI::log( sprintf( '  [%d] %s%s  %s', $source['n'], $source['title'], '' !== $source['heading'] ? ' > ' . $source['heading'] : '', $source['url'] ) );
		}
		if ( null !== $result && ! empty( $assoc_args['passages'] ) ) {
			foreach ( $result->retrieved as $index => $hit ) {
				\WP_CLI::log( sprintf( '  passage %d  sim=%.3f  %s%s', $index + 1, $hit->similarity, $hit->passage->title, '' !== $hit->passage->heading ? ' > ' . $hit->passage->heading : '' ) );
			}
		}
		if ( null !== $result ) {
			\WP_CLI::log( sprintf( 'reason=%s best_similarity=%.3f latency=%dms cost=%s', '' === $result->reason ? '-' : $result->reason, $result->best_similarity, $result->latency_ms, null === $result->cost() ? '?' : number_format( (float) $result->cost(), 6 ) ) );
		}
	}

	/**
	 * Runs a labelled test set and writes a report.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON file: {"questions": [{"id", "question", "expect": "answer|refuse|trap", "pages": [slugs], "facts": [[spellings]]}]}.
	 *
	 * [--report=<file>]
	 * : Write the Markdown report here.
	 *
	 * [--results=<file>]
	 * : Write the scored rows as JSON here. (Not --json: WP-CLI reads that as --format=json.)
	 *
	 * @param array<int, string>   $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Options.
	 */
	public function evaluate( array $args, array $assoc_args ): void {
		$file = (string) ( $args[0] ?? '' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file given on the command line.
		$data = is_readable( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		if ( ! is_array( $data ) ) {
			\WP_CLI::error( 'Cannot read the test set: ' . $file );
		}

		$scorer = new EvalScorer( static fn( int $post_id ): string => (string) get_post_field( 'post_name', $post_id ) );
		$rows   = array();
		foreach ( EvalCase::from_array( $data ) as $case ) {
			$reply = $this->chat->ask( $case->question, null, false );
			if ( null === $reply['result'] ) {
				\WP_CLI::error( sprintf( '%s: %s', $case->id, (string) $reply['body']['answer'] ) );
			}
			$row    = $scorer->score( $case, $reply['result'] );
			$rows[] = $row;
			\WP_CLI::log( sprintf( '%-4s %-7s %-9s %s', $row['id'], $row['correct'] ? 'ok' : 'WRONG', $row['status'], $case->question ) );
		}

		$header = sprintf(
			'Run on %s UTC with `%s` and `%s` (%d dimensions), %d passages per answer, relevance gate %.2f, hybrid search (vectors + BM25, RRF).',
			gmdate( 'Y-m-d H:i' ),
			(string) $this->settings->get( 'chat_model' ),
			(string) $this->settings->get( 'embedding_model' ),
			$this->settings->int( 'dimensions' ),
			$this->settings->int( 'top_k' ),
			(float) $this->settings->get( 'min_similarity' )
		);
		$totals = EvalReport::totals( $rows );
		\WP_CLI::log( wp_json_encode( $totals ) );

		if ( ! empty( $assoc_args['report'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local file given on the command line.
			file_put_contents( (string) $assoc_args['report'], EvalReport::markdown( $rows, $header ) );
		}
		if ( ! empty( $assoc_args['results'] ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local file given on the command line.
			file_put_contents(
				(string) $assoc_args['results'],
				(string) wp_json_encode(
					array(
						'header' => $header,
						'totals' => $totals,
						'rows'   => $rows,
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
				)
			);
		}
		\WP_CLI::success( sprintf( '%d of %d correct.', count( array_filter( array_column( $rows, 'correct' ) ) ), count( $rows ) ) );
	}
}
