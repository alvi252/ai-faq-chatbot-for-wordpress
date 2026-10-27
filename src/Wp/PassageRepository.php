<?php
/**
 * Indexed passages.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Answer\PassageSource;
use AIFAQ\Core\Content\Passage;
use AIFAQ\Core\Search\Vectors;

defined( 'ABSPATH' ) || exit;

/**
 * One row per passage with its packed embedding (2 KB at 512 dimensions).
 * Search reads all rows of the current model into memory, which suits sites
 * up to a few thousand passages; see the README for measured timings.
 */
final class PassageRepository implements PassageSource {

	/**
	 * Returns the table name with the site prefix.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'aifaq_passages';
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
  post_id bigint(20) unsigned NOT NULL,
  position int(10) unsigned NOT NULL DEFAULT 0,
  title text NOT NULL,
  url text NOT NULL,
  heading text NOT NULL,
  content mediumtext NOT NULL,
  embedding mediumblob NOT NULL,
  model varchar(100) NOT NULL DEFAULT '',
  dimensions smallint(5) unsigned NOT NULL DEFAULT 0,
  indexed_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY model (model, dimensions)
) {$collate};"
		);
	}

	/**
	 * Replaces the passages of one post.
	 *
	 * @param int       $post_id    Post ID.
	 * @param Passage[] $passages   Passages with vectors.
	 * @param string    $model      Embedding model.
	 * @param int       $dimensions Vector length setting.
	 * @return bool Whether every passage was written.
	 */
	public function replace_post( int $post_id, array $passages, string $model, int $dimensions ): bool {
		global $wpdb;

		$this->delete_post( $post_id );
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = true;
		foreach ( $passages as $passage ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			$ok = false !== $wpdb->insert(
				self::table_name(),
				array(
					'post_id'    => $post_id,
					'position'   => $passage->position,
					'title'      => $passage->title,
					'url'        => $passage->url,
					'heading'    => $passage->heading,
					'content'    => $passage->text,
					'embedding'  => Vectors::pack( $passage->vector ),
					'model'      => $model,
					'dimensions' => $dimensions,
					'indexed_at' => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s' )
			) && $ok;
		}
		return $ok;
	}

	/**
	 * Whether a post has passages.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function has_post( int $post_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; must be current.
		return null !== $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE post_id = %d LIMIT 1', self::table_name(), $post_id ) );
	}

	/**
	 * Deletes the passages of one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function delete_post( int $post_id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		$wpdb->delete( self::table_name(), array( 'post_id' => $post_id ), array( '%d' ) );
	}

	/**
	 * Deletes passages of posts not in the list (after the indexed post types changed).
	 *
	 * @param int[] $keep Post IDs that stay.
	 * @return int Rows deleted.
	 */
	public function delete_except( array $keep ): int {
		global $wpdb;
		$stored = array_map( 'intval', $this->post_ids() );
		$gone   = array_diff( $stored, array_map( 'intval', $keep ) );
		foreach ( $gone as $post_id ) {
			$this->delete_post( $post_id );
			delete_post_meta( $post_id, IndexScheduler::HASH_META );
		}
		return count( $gone );
	}

	/**
	 * Every passage embedded with the given model and length.
	 *
	 * @param string $model      Embedding model.
	 * @param int    $dimensions Vector length setting.
	 * @return Passage[]
	 */
	public function passages( string $model, int $dimensions ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; read once per question.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, post_id, position, title, url, heading, content, embedding FROM %i WHERE model = %s AND dimensions = %d ORDER BY post_id, position', self::table_name(), $model, $dimensions ),
			ARRAY_A
		);

		$passages = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$passages[] = new Passage(
				(int) $row['post_id'],
				(string) $row['title'],
				(string) $row['url'],
				(string) $row['heading'],
				(string) $row['content'],
				(int) $row['position'],
				Vectors::unpack( (string) $row['embedding'] ),
				(int) $row['id']
			);
		}
		return $passages;
	}

	/**
	 * IDs of posts with passages.
	 *
	 * @return int[]
	 */
	public function post_ids(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT post_id FROM %i', self::table_name() ) ) );
	}

	/**
	 * Passages per post, for the Index tab.
	 *
	 * @return array<int, array{post_id: int, title: string, url: string, passages: int, model: string, dimensions: int, indexed_at: string}>
	 */
	public function per_post(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table, admin screen only.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT post_id, MAX(title) AS title, MAX(url) AS url, COUNT(*) AS passages, MAX(model) AS model, MAX(dimensions) AS dimensions, MAX(indexed_at) AS indexed_at FROM %i GROUP BY post_id ORDER BY title', self::table_name() ),
			ARRAY_A
		);
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array(
				'post_id'    => (int) $row['post_id'],
				'title'      => (string) $row['title'],
				'url'        => (string) $row['url'],
				'passages'   => (int) $row['passages'],
				'model'      => (string) $row['model'],
				'dimensions' => (int) $row['dimensions'],
				'indexed_at' => (string) $row['indexed_at'],
			);
		}
		return $out;
	}

	/**
	 * Deletes everything.
	 */
	public function clear(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Custom table.
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', self::table_name() ) );
		delete_post_meta_by_key( IndexScheduler::HASH_META );
	}
}
