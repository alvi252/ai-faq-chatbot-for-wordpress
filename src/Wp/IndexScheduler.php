<?php
/**
 * When indexing runs.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

defined( 'ABSPATH' ) || exit;

/**
 * A saved post is indexed a few seconds later by WP-Cron, so the editor is not
 * kept waiting for the embedding API. A post that is unpublished, trashed or
 * deleted leaves the index at once. A full rebuild runs in batches through
 * WP-Cron so a slow host does not time out.
 */
final class IndexScheduler {

	public const POST_HOOK  = 'aifaq_index_post';
	public const BATCH_HOOK = 'aifaq_index_batch';
	public const QUEUE      = 'aifaq_index_queue';
	public const HASH_META  = '_aifaq_indexed_hash';
	public const BATCH_SIZE = 10;

	/**
	 * Constructor.
	 *
	 * @param Indexer $indexer Indexer.
	 */
	public function __construct( private Indexer $indexer ) {
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'save_post', array( $this, 'on_save' ), 20, 2 );
		add_action( 'woocommerce_update_product', array( $this, 'schedule_post' ) );
		// Stock changes from orders do not save the product, so they are caught here.
		add_action( 'woocommerce_product_set_stock_status', array( $this, 'schedule_post' ) );
		add_action( 'transition_post_status', array( $this, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( $this->indexer, 'remove_post' ) );
		add_action( self::POST_HOOK, array( $this, 'run_post' ) );
		add_action( self::BATCH_HOOK, array( $this, 'run_batch' ) );
	}

	/**
	 * A post was saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status || ! $this->indexer->concerns( $post ) ) {
			return;
		}
		// Excluded, password-protected or otherwise unfit: out of the answers now, not after WP-Cron.
		if ( ! $this->indexer->belongs( $post ) ) {
			$this->indexer->remove_post( $post_id );
			return;
		}
		$this->schedule_post( $post_id );
	}

	/**
	 * A post left the published state: remove it now rather than after a delay.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->indexer->remove_post( (int) $post->ID );
		}
	}

	/**
	 * Schedules one post, once.
	 *
	 * @param int $post_id Post ID.
	 */
	public function schedule_post( int $post_id ): void {
		$args = array( (int) $post_id );
		if ( false === wp_next_scheduled( self::POST_HOOK, $args ) ) {
			wp_schedule_single_event( time() + 5, self::POST_HOOK, $args );
		}
	}

	/**
	 * Cron: index one post.
	 *
	 * @param int $post_id Post ID.
	 */
	public function run_post( int $post_id ): void {
		$this->indexer->index_post( $post_id );
	}

	/**
	 * Starts a full rebuild: queues every indexable post and processes the first batch now.
	 *
	 * @param int[] $ids   Post IDs to index.
	 * @param bool  $force Re-embed unchanged posts too.
	 * @return array<string, int> Counts per status for the first batch.
	 */
	public function start_rebuild( array $ids, bool $force ): array {
		update_option(
			self::QUEUE,
			array(
				'ids'   => array_values( array_map( 'intval', $ids ) ),
				'force' => $force,
				'total' => count( $ids ),
			),
			false
		);
		return $this->run_batch();
	}

	/**
	 * Cron: processes the next batch of the rebuild queue and schedules the rest.
	 *
	 * @return array<string, int> Counts per status.
	 */
	public function run_batch(): array {
		$queue = get_option( self::QUEUE );
		if ( ! is_array( $queue ) || array() === ( $queue['ids'] ?? array() ) ) {
			delete_option( self::QUEUE );
			return array();
		}

		// The queue is saved and the next batch scheduled before any work, so a
		// request that dies mid-batch (a time limit, a fatal error in a shortcode)
		// loses at most this batch instead of stranding the whole rebuild.
		$batch = array_splice( $queue['ids'], 0, self::BATCH_SIZE );
		$this->save_queue( $queue );

		$counts = array();
		foreach ( $batch as $index => $post_id ) {
			$outcome                      = $this->indexer->index_post( (int) $post_id, ! empty( $queue['force'] ) );
			$counts[ $outcome['status'] ] = ( $counts[ $outcome['status'] ] ?? 0 ) + 1;
			if ( 'error' === $outcome['status'] && 'not_configured' === $outcome['error'] ) {
				// No API key: put the rest back and stop until the next rebuild.
				$queue['ids'] = array_merge( array_slice( $batch, $index ), $queue['ids'] );
				update_option( self::QUEUE, $queue, false );
				wp_clear_scheduled_hook( self::BATCH_HOOK );
				break;
			}
		}
		return $counts;
	}

	/**
	 * Stores the remaining queue and schedules the next batch, or ends the rebuild.
	 *
	 * @param array<string, mixed> $queue Queue.
	 */
	private function save_queue( array $queue ): void {
		if ( array() === $queue['ids'] ) {
			delete_option( self::QUEUE );
			return;
		}
		update_option( self::QUEUE, $queue, false );
		if ( false === wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 1, self::BATCH_HOOK );
		}
	}

	/**
	 * Rebuild progress.
	 *
	 * @return array{remaining: int, total: int}|null Null when no rebuild is running.
	 */
	public function progress(): ?array {
		$queue = get_option( self::QUEUE );
		if ( ! is_array( $queue ) ) {
			return null;
		}
		return array(
			'remaining' => count( (array) ( $queue['ids'] ?? array() ) ),
			'total'     => (int) ( $queue['total'] ?? 0 ),
		);
	}
}
