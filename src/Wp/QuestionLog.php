<?php
/**
 * Visitor questions.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Answer\AnswerResult;

defined( 'ABSPATH' ) || exit;

/**
 * Questions and what happened to them, so the site owner can see what visitors
 * ask that the site does not answer. No IP address, user agent or account is
 * stored; rows are deleted after the "keep" setting (30 days by default).
 */
final class QuestionLog {

	public const PRUNE_HOOK = 'aifaq_prune_questions';

	/**
	 * Returns the table name with the site prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifaq_questions';
	}

	/**
	 * Creates or updates the table.
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table_name();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  question text NOT NULL,
  status varchar(20) NOT NULL DEFAULT '',
  reason varchar(40) NOT NULL DEFAULT '',
  page_ids varchar(255) NOT NULL DEFAULT '',
  latency_ms int(10) unsigned NOT NULL DEFAULT 0,
  cost decimal(16,9) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY status (status)
) {$collate};"
		);
	}

	/**
	 * Stores a question.
	 *
	 * @param string       $question Question.
	 * @param AnswerResult $result   Result.
	 */
	public function record( string $question, AnswerResult $result ): void {
		global $wpdb;
		$pages = array();
		foreach ( $result->sources as $hit ) {
			$pages[] = $hit->passage->post_id;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
		$wpdb->insert(
			self::table_name(),
			array(
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
				'question'   => mb_substr( $question, 0, 1000 ),
				'status'     => $result->status,
				'reason'     => substr( $result->reason, 0, 40 ),
				'page_ids'   => substr( implode( ',', array_unique( $pages ) ), 0, 255 ),
				'latency_ms' => $result->latency_ms,
				'cost'       => null === $result->cost() ? null : sprintf( '%.9F', (float) $result->cost() ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	/**
	 * Recent questions, optionally only those not answered.
	 *
	 * @param bool $unanswered_only Only refused and failed questions.
	 * @param int  $limit           Maximum rows.
	 * @return array<int, array<string, mixed>>
	 */
	public function recent( bool $unanswered_only, int $limit = 100 ): array {
		global $wpdb;
		$table = self::table_name();
		if ( $unanswered_only ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE status <> %s ORDER BY id DESC LIMIT %d', $table, AnswerResult::ANSWERED, $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ), ARRAY_A );
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts per status for the last days.
	 *
	 * @param int $days Days.
	 * @return array<string, int>
	 */
	public function counts( int $days = 30 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i WHERE created_at >= %s GROUP BY status', self::table_name(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * Deletes questions older than the given number of days.
	 *
	 * @param int $days Days to keep.
	 */
	public function prune( int $days ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table_name(), gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS ) ) );
	}
}
