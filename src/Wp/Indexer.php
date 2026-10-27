<?php
/**
 * Keeps the index in step with the site.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Content\Passage;
use AIFAQ\Core\Provider\ProviderException;
use AIFAQ\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Indexes one post: cuts it into passages, embeds them (only when the content
 * or the embedding settings changed since the last time), stores them and
 * logs the call. A post that is no longer indexable loses its passages.
 */
final class Indexer {

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings Settings.
	 * @param ContentSource     $content  Content.
	 * @param PassageRepository $passages Passages.
	 * @param CallLog           $calls    Call log.
	 * @param Services          $services Factory for API clients.
	 */
	public function __construct(
		private Settings $settings,
		private ContentSource $content,
		private PassageRepository $passages,
		private CallLog $calls,
		private Services $services
	) {
	}

	/**
	 * Indexes one post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Embed even when nothing changed.
	 * @return array{status: string, passages: int, tokens: int, error: string} Status: indexed, unchanged, removed, empty or error.
	 */
	public function index_post( int $post_id, bool $force = false ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || ! $this->content->is_indexable( $post ) ) {
			$this->remove_post( $post_id );
			return self::outcome( 'removed' );
		}

		$passages = $this->content->passages( $post );
		if ( array() === $passages ) {
			$this->remove_post( $post_id );
			return self::outcome( 'empty' );
		}

		$model       = (string) $this->settings->get( 'embedding_model' );
		$dims        = $this->settings->int( 'dimensions' );
		$fingerprint = ContentSource::fingerprint( $passages, $model, $dims );
		if ( ! $force && get_post_meta( $post_id, IndexScheduler::HASH_META, true ) === $fingerprint ) {
			return self::outcome( 'unchanged', count( $passages ) );
		}

		if ( ! $this->settings->is_configured() ) {
			return self::outcome( 'error', 0, 0, 'not_configured' );
		}

		$embeddings = $this->services->embeddings();
		try {
			$result = $embeddings->embed( array_map( static fn( Passage $p ): string => $p->embedding_input(), $passages ) );
		} catch ( ProviderException $e ) {
			$this->calls->record( array( $this->services->call_row( 'embedding', $model, 'error', 0, 0, $e->get_reason() ) ), 'index' );
			return self::outcome( 'error', 0, 0, $e->get_reason() );
		}

		$with_vectors = array();
		foreach ( $passages as $index => $passage ) {
			$with_vectors[] = $passage->with_vector( $result->vectors[ $index ] );
		}
		$this->calls->record( array( $this->services->call_row( 'embedding', $result->model, 'success', $result->tokens, 0 ) ), 'index' );
		if ( ! $this->passages->replace_post( $post_id, $with_vectors, $model, $dims ) ) {
			// No fingerprint: the next save or rebuild tries again instead of skipping the post.
			delete_post_meta( $post_id, IndexScheduler::HASH_META );
			return self::outcome( 'error', 0, $result->tokens, 'db_write' );
		}
		update_post_meta( $post_id, IndexScheduler::HASH_META, $fingerprint );

		return self::outcome( 'indexed', count( $passages ), $result->tokens );
	}

	/**
	 * Whether a save of this post concerns the index: its type is indexed, or it
	 * has passages that may have to go. Other saves (orders, menus, templates)
	 * schedule nothing.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function concerns( \WP_Post $post ): bool {
		return in_array( $post->post_type, $this->settings->post_types(), true ) || $this->passages->has_post( (int) $post->ID );
	}

	/**
	 * Whether the post belongs in the index right now.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function belongs( \WP_Post $post ): bool {
		return $this->content->is_indexable( $post );
	}

	/**
	 * Removes from the index every post that no longer belongs there (after the
	 * indexed types or the excluded IDs changed).
	 *
	 * @return int Posts removed.
	 */
	public function prune(): int {
		return $this->passages->delete_except( $this->content->indexable_ids() );
	}

	/**
	 * Removes a post from the index.
	 *
	 * @param int $post_id Post ID.
	 */
	public function remove_post( int $post_id ): void {
		$this->passages->delete_post( $post_id );
		delete_post_meta( $post_id, IndexScheduler::HASH_META );
	}

	/**
	 * Result array.
	 *
	 * @param string $status   Status.
	 * @param int    $passages Passages.
	 * @param int    $tokens   Tokens embedded.
	 * @param string $error    Error reason.
	 * @return array{status: string, passages: int, tokens: int, error: string}
	 */
	private static function outcome( string $status, int $passages = 0, int $tokens = 0, string $error = '' ): array {
		return array(
			'status'   => $status,
			'passages' => $passages,
			'tokens'   => $tokens,
			'error'    => $error,
		);
	}
}
