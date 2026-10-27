<?php
/**
 * API calls with tokens and cost.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

defined( 'ABSPATH' ) || exit;

/**
 * One row per API call (embedding for indexing, embedding of a question,
 * answer), successful or not. The daily budget is checked against this table.
 */
final class CallLog {

	public const RETENTION_DAYS = 180;

	/**
	 * Returns the table name with the site prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifaq_calls';
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
  kind varchar(20) NOT NULL DEFAULT '',
  model varchar(100) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT '',
  error_code varchar(40) NOT NULL DEFAULT '',
  input_tokens int(10) unsigned NOT NULL DEFAULT 0,
  output_tokens int(10) unsigned NOT NULL DEFAULT 0,
  cost decimal(16,9) NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at)
) {$collate};"
		);
	}

	/**
	 * Stores calls.
	 *
	 * @param array<int, array<string, mixed>> $calls Calls as built by Answerer or Indexer.
	 * @param string                           $kind_prefix "question" or "index", stored before the call kind.
	 */
	public function record( array $calls, string $kind_prefix ): void {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		foreach ( $calls as $call ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			$wpdb->insert(
				self::table_name(),
				array(
					'created_at'    => $now,
					'kind'          => substr( $kind_prefix . ':' . (string) $call['kind'], 0, 20 ),
					'model'         => substr( (string) $call['model'], 0, 100 ),
					'status'        => (string) $call['status'],
					'error_code'    => substr( (string) ( $call['error'] ?? '' ), 0, 40 ),
					'input_tokens'  => (int) $call['input_tokens'],
					'output_tokens' => (int) $call['output_tokens'],
					// As a string: wpdb formats %f with 6 decimals, which rounds a question's embedding cost to 0.
					'cost'          => null === $call['cost'] ? null : sprintf( '%.9F', (float) $call['cost'] ),
				),
				array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
			);
		}
	}

	/**
	 * Estimated spend since midnight UTC.
	 *
	 * @return float
	 */
	public function spent_today(): float {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must be current.
		$sum = $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(cost), 0) FROM %i WHERE created_at >= %s', self::table_name(), gmdate( 'Y-m-d 00:00:00' ) ) );
		return (float) $sum;
	}

	/**
	 * Totals per kind and model for the last days.
	 *
	 * @param int $days Number of days.
	 * @return array<int, array<string, mixed>>
	 */
	public function summary( int $days = 30 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT kind, model, COUNT(*) AS calls, SUM(status <> 'success') AS errors,
					COALESCE(SUM(input_tokens), 0) AS input_tokens, COALESCE(SUM(output_tokens), 0) AS output_tokens,
					SUM(cost) AS cost, SUM(cost IS NULL AND input_tokens + output_tokens > 0) AS unpriced
				FROM %i WHERE created_at >= %s GROUP BY kind, model ORDER BY kind, model",
				self::table_name(),
				gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Deletes rows older than the retention period.
	 */
	public function prune(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table_name(), gmdate( 'Y-m-d H:i:s', time() - self::RETENTION_DAYS * DAY_IN_SECONDS ) ) );
	}
}
