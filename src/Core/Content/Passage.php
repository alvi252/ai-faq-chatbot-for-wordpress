<?php
/**
 * A passage of a page.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Content;

defined( 'ABSPATH' ) || exit;

/**
 * The unit that is embedded, searched and cited: a piece of one page under one heading.
 */
final class Passage {

	/**
	 * Constructor.
	 *
	 * @param int     $post_id  Post ID of the page.
	 * @param string  $title    Page title.
	 * @param string  $url      Page URL.
	 * @param string  $heading  Nearest heading above the passage, or empty.
	 * @param string  $text     Plain text.
	 * @param int     $position Order within the page, from 0.
	 * @param float[] $vector   Unit-length embedding, empty until embedded.
	 * @param int     $id       Row ID once stored, else 0.
	 */
	public function __construct(
		public readonly int $post_id,
		public readonly string $title,
		public readonly string $url,
		public readonly string $heading,
		public readonly string $text,
		public readonly int $position = 0,
		public readonly array $vector = array(),
		public readonly int $id = 0
	) {
	}

	/**
	 * Text sent to the embedding model: the page title and heading give the
	 * passage its context ("Returns > Opened food" rather than a bare paragraph).
	 *
	 * @return string
	 */
	public function embedding_input(): string {
		$label = '' !== $this->heading ? $this->title . ' > ' . $this->heading : $this->title;
		return $label . "\n" . $this->text;
	}

	/**
	 * Copy with an embedding.
	 *
	 * @param float[] $vector Unit-length vector.
	 * @return self
	 */
	public function with_vector( array $vector ): self {
		return new self( $this->post_id, $this->title, $this->url, $this->heading, $this->text, $this->position, $vector, $this->id );
	}
}
