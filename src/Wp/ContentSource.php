<?php
/**
 * What of a post gets indexed.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Core\Content\Chunker;
use AIFAQ\Core\Content\Passage;
use AIFAQ\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which posts are indexed and turns one into passages: a page by its
 * rendered blocks and shortcodes, a WooCommerce product by its descriptions,
 * price, stock status and visible attributes.
 */
final class ContentSource {

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Whether a post belongs in the index: published, public, not password
	 * protected, of an indexed type and not excluded.
	 *
	 * @param \WP_Post $post Post.
	 * @return bool
	 */
	public function is_indexable( \WP_Post $post ): bool {
		$indexable = 'publish' === $post->post_status
			&& '' === $post->post_password
			&& in_array( $post->post_type, $this->settings->post_types(), true )
			&& ! in_array( (int) $post->ID, $this->settings->excluded_ids(), true );

		// Cart, checkout and account pages hold forms, not answers, and their blocks expect a shopper session.
		if ( $indexable && 'page' === $post->post_type && function_exists( 'wc_get_page_id' ) ) {
			$indexable = ! in_array( (int) $post->ID, array( (int) wc_get_page_id( 'cart' ), (int) wc_get_page_id( 'checkout' ), (int) wc_get_page_id( 'myaccount' ) ), true );
		}
		if ( $indexable && 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product   = wc_get_product( $post->ID );
			$indexable = $product instanceof \WC_Product && 'hidden' !== $product->get_catalog_visibility();
		}

		/**
		 * Filters whether a post is indexed for the chatbot.
		 *
		 * @param bool     $indexable Whether the post is indexed.
		 * @param \WP_Post $post      Post.
		 */
		return (bool) apply_filters( 'aifaq_is_indexable', $indexable, $post );
	}

	/**
	 * IDs of every post that belongs in the index.
	 *
	 * @return int[]
	 */
	public function indexable_ids(): array {
		$types = $this->settings->post_types();
		if ( array() === $types ) {
			return array();
		}
		// Whole posts, not IDs, so they arrive with their caches primed. Filters stay
		// suppressed (the get_posts() default): a multilingual plugin must not narrow
		// the list to the admin's language, or a rebuild would drop the other languages.
		$posts = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'post__not_in'   => $this->settings->excluded_ids(), // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- Short list from the settings.
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			if ( $post instanceof \WP_Post && $this->is_indexable( $post ) ) {
				$out[] = (int) $post->ID;
			}
		}
		return $out;
	}

	/**
	 * Passages of a post, without vectors.
	 *
	 * @param \WP_Post $post Post.
	 * @return Passage[]
	 */
	public function passages( \WP_Post $post ): array {
		$title = html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url   = (string) get_permalink( $post );

		/**
		 * Filters the HTML a post is indexed from.
		 *
		 * @param string   $html HTML.
		 * @param \WP_Post $post Post.
		 */
		$html = (string) apply_filters( 'aifaq_post_html', $this->html( $post ), $post );
		return Chunker::passages( (int) $post->ID, $title, $url, $html );
	}

	/**
	 * HTML of a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string
	 */
	private function html( \WP_Post $post ): string {
		if ( 'product' === $post->post_type && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product instanceof \WC_Product ) {
				return $this->product_html( $product );
			}
		}
		return $this->render( $post->post_content );
	}

	/**
	 * Renders blocks and shortcodes (FAQ and accordion plugins often use shortcodes).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function render( string $content ): string {
		$html = function_exists( 'do_blocks' ) ? do_blocks( $content ) : $content;
		return do_shortcode( $html );
	}

	/**
	 * A product as HTML: the facts a shopper asks about first, then the descriptions.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private function product_html( \WC_Product $product ): string {
		$facts = array();
		$price = self::price_text( $product );
		if ( '' !== $price ) {
			$facts[] = 'Price: ' . $price;
		}
		$stock = self::stock_text( $product );
		if ( '' !== $stock ) {
			$facts[] = $stock;
		}
		if ( '' !== $product->get_sku() ) {
			$facts[] = 'SKU: ' . $product->get_sku();
		}
		foreach ( $product->get_attributes() as $attribute ) {
			if ( $attribute instanceof \WC_Product_Attribute && $attribute->get_visible() ) {
				$values  = $attribute->is_taxonomy() ? wc_get_product_terms( $product->get_id(), $attribute->get_name(), array( 'fields' => 'names' ) ) : $attribute->get_options();
				$facts[] = wc_attribute_label( $attribute->get_name() ) . ': ' . implode( ', ', array_map( 'strval', (array) $values ) );
			}
		}

		$html  = '<ul><li>' . implode( '</li><li>', array_map( 'esc_html', $facts ) ) . '</li></ul>';
		$html .= $this->render( $product->get_short_description() );
		$html .= '<h2>' . esc_html__( 'Description', 'ai-faq-chatbot' ) . '</h2>' . $this->render( $product->get_description() );
		return $html;
	}

	/**
	 * The price as a shopper sees it: a range for a variable product, the sale and
	 * regular price when on sale, nothing when the product has no price
	 * ("price on request"), so the bot never states a price of 0.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private static function price_text( \WC_Product $product ): string {
		if ( '' === (string) $product->get_price() ) {
			return '';
		}
		$format = static fn( $amount ): string => wp_strip_all_tags( html_entity_decode( wc_price( (float) $amount ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

		if ( $product instanceof \WC_Product_Variable ) {
			$min = $product->get_variation_price( 'min', true );
			$max = $product->get_variation_price( 'max', true );
			return (float) $min === (float) $max ? $format( $min ) : sprintf( 'from %s to %s', $format( $min ), $format( $max ) );
		}

		$current = wc_get_price_to_display( $product );
		if ( $product->is_on_sale() && '' !== (string) $product->get_regular_price() ) {
			$regular = wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
			return sprintf( '%s (on sale, regular price %s)', $format( $current ), $format( $regular ) );
		}
		return $format( $current );
	}

	/**
	 * Stock status in words.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	private static function stock_text( \WC_Product $product ): string {
		switch ( $product->get_stock_status() ) {
			case 'instock':
				return 'In stock';
			case 'outofstock':
				return 'Out of stock';
			case 'onbackorder':
				return 'Out of stock, available on backorder';
			default:
				return '';
		}
	}

	/**
	 * Fingerprint of a post's indexed content and the embedding settings, to skip unchanged posts.
	 *
	 * @param Passage[] $passages Passages.
	 * @param string    $model    Embedding model.
	 * @param int       $dims     Vector length.
	 * @return string
	 */
	public static function fingerprint( array $passages, string $model, int $dims ): string {
		$parts = array( $model, (string) $dims );
		foreach ( $passages as $passage ) {
			$parts[] = $passage->embedding_input() . "\x1f" . $passage->url;
		}
		return sha1( implode( "\x1e", $parts ) );
	}
}
