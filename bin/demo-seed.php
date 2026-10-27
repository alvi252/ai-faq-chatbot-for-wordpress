<?php
/**
 * Seeds the invented PawPantry shop: its help pages, a FAQ page and products.
 * Run with: wp eval-file wp-content/plugins/ai-faq-chatbot/bin/demo-seed.php
 * Development helper only; not part of the plugin package.
 *
 * @package AIFAQ
 */

defined( 'ABSPATH' ) || exit;

$aifaq_dir = dirname( __DIR__ ) . '/demo';

// Remove WordPress's sample content so it does not end up in the index.
foreach ( array( 'sample-page' => 'page', 'hello-world' => 'post' ) as $aifaq_slug => $aifaq_type ) {
	$aifaq_post = get_page_by_path( $aifaq_slug, OBJECT, $aifaq_type );
	if ( $aifaq_post ) {
		wp_delete_post( $aifaq_post->ID, true );
	}
}

$aifaq_pages = array();
foreach ( glob( $aifaq_dir . '/pages/*.html' ) as $aifaq_file ) {
	$aifaq_html  = (string) file_get_contents( $aifaq_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	$aifaq_title = preg_match( '/<!-- title: (.+?) -->/', $aifaq_html, $aifaq_m ) ? $aifaq_m[1] : basename( $aifaq_file, '.html' );
	$aifaq_body  = trim( (string) preg_replace( '/^<!-- title: .+? -->\n/', '', $aifaq_html ) );
	$aifaq_slug  = basename( $aifaq_file, '.html' );
	$aifaq_found = get_page_by_path( $aifaq_slug, OBJECT, 'page' );
	$aifaq_id    = wp_insert_post(
		array(
			'ID'           => $aifaq_found ? $aifaq_found->ID : 0,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $aifaq_title,
			'post_name'    => $aifaq_slug,
			'post_content' => $aifaq_body,
		)
	);
	$aifaq_pages[ $aifaq_slug ] = (int) $aifaq_id;
	WP_CLI::log( sprintf( 'Page %d: %s', $aifaq_id, $aifaq_title ) );
}

update_option( 'woocommerce_currency', 'EUR' );
// New WooCommerce stores start in "coming soon" mode; the demo shop is open.
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_default_country', 'FR' );

$aifaq_products = json_decode( (string) file_get_contents( $aifaq_dir . '/products.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
foreach ( $aifaq_products['products'] as $aifaq_row ) {
	$aifaq_existing = wc_get_product_id_by_sku( $aifaq_row['sku'] );
	$aifaq_product  = $aifaq_existing ? wc_get_product( $aifaq_existing ) : new WC_Product_Simple();
	$aifaq_product->set_name( $aifaq_row['name'] );
	$aifaq_product->set_slug( $aifaq_row['slug'] );
	$aifaq_product->set_sku( $aifaq_row['sku'] );
	$aifaq_product->set_regular_price( $aifaq_row['price'] );
	$aifaq_product->set_short_description( $aifaq_row['short'] );
	$aifaq_product->set_description( $aifaq_row['description'] );
	$aifaq_product->set_status( 'publish' );
	$aifaq_product->set_stock_status( empty( $aifaq_row['out_of_stock'] ) ? 'instock' : 'outofstock' );
	$aifaq_attributes = array();
	foreach ( $aifaq_row['attributes'] as $aifaq_name => $aifaq_value ) {
		$aifaq_attribute = new WC_Product_Attribute();
		$aifaq_attribute->set_name( $aifaq_name );
		$aifaq_attribute->set_options( array( $aifaq_value ) );
		$aifaq_attribute->set_visible( true );
		$aifaq_attributes[] = $aifaq_attribute;
	}
	$aifaq_product->set_attributes( $aifaq_attributes );
	WP_CLI::log( sprintf( 'Product %d: %s', $aifaq_product->save(), $aifaq_row['name'] ) );
}

// Start from the defaults: the settings sanitizer treats a missing checkbox as unticked.
$aifaq_settings = get_option( 'aifaq_settings' );
update_option(
	'aifaq_settings',
	array_merge(
		AIFAQ\Settings::defaults(),
		is_array( $aifaq_settings ) ? $aifaq_settings : array(),
		array(
			'enabled'       => true,
			'log_questions' => true,
			// Every request of the demo comes from one address; the default of 20 an hour would stop the recordings.
			'per_visitor_hour' => 100,
			'site_name'   => 'PawPantry',
			'title'       => 'Ask PawPantry',
			'welcome'     => "Hi! Ask me about delivery, returns, payment, Autoship or our products. I answer from this website's pages and show you where I found it.",
			'contact_url' => get_permalink( $aifaq_pages['contact-and-opening-hours'] ?? 0 ),
			'accent'      => '#1f2a37',
			'post_types'  => array( 'page', 'product' ),
		)
	)
);
update_option( 'blogname', 'PawPantry' );
update_option( 'blogdescription', 'Pet food and accessories, delivered across Europe (invented demo shop)' );
WP_CLI::success( 'PawPantry demo content is ready. Index it with: wp aifaq index' );
