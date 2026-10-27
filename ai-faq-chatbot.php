<?php
/**
 * Plugin Name:       AI FAQ Chatbot
 * Description:       A chat window that answers visitors only from this site's own pages, FAQ and products, links the page behind every answer and says "I don't know" otherwise. Uses the OpenAI API with your key; daily budget and per-visitor limits included.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Aleks T.
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-faq-chatbot
 * Domain Path:       /languages
 *
 * @package AIFAQ
 */

defined( 'ABSPATH' ) || exit;

define( 'AIFAQ_VERSION', '1.0.0' );
define( 'AIFAQ_PLUGIN_FILE', __FILE__ );
define( 'AIFAQ_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AIFAQ_PLUGIN_DIR . 'src/Autoloader.php';
AIFAQ\Autoloader::register( AIFAQ_PLUGIN_DIR . 'src/' );

register_activation_hook( __FILE__, array( AIFAQ\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( AIFAQ\Installer::class, 'deactivate' ) );

// The plugin reads products but never touches orders, so it is compatible with High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( AIFAQ\Plugin::class, 'boot' ) );
