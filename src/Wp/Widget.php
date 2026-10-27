<?php
/**
 * The chat window on the site.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Wp;

use AIFAQ\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * A floating button on every page (when enabled), or an inline chat where the
 * [aifaq_chatbot] shortcode is placed. Plain JavaScript, no build step.
 */
final class Widget {

	/**
	 * Whether the shortcode rendered on this page (then the floating button is not added).
	 *
	 * @var bool
	 */
	private bool $inline = false;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( private Settings $settings ) {
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_shortcode( 'aifaq_chatbot', array( $this, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'wp_footer', array( $this, 'render_floating' ) );
	}

	/**
	 * Whether the chat shows on the site.
	 *
	 * @return bool
	 */
	private function active(): bool {
		return ! empty( $this->settings->get( 'enabled' ) ) && $this->settings->is_configured();
	}

	/**
	 * Registers and enqueues the assets.
	 */
	public function enqueue(): void {
		if ( ! $this->active() ) {
			return;
		}
		wp_register_style( 'aifaq-widget', plugins_url( 'css/widget.css', AIFAQ_PLUGIN_FILE ), array(), AIFAQ_VERSION );
		wp_add_inline_style( 'aifaq-widget', sprintf( '.aifaq-chat{--aifaq-accent:%s}', sanitize_hex_color( (string) $this->settings->get( 'accent' ) ) ?? '#1f2a37' ) );
		wp_register_script( 'aifaq-widget', plugins_url( 'js/widget.js', AIFAQ_PLUGIN_FILE ), array(), AIFAQ_VERSION, array( 'in_footer' => true ) );
		wp_localize_script(
			'aifaq-widget',
			'aifaqChat',
			array(
				'endpoint'  => esc_url_raw( rest_url( RestController::NAMESPACE . '/ask' ) ),
				'title'     => (string) $this->settings->get( 'title' ),
				'welcome'   => (string) $this->settings->get( 'welcome' ),
				'maxLength' => Chat::MAX_LENGTH,
				'i18n'      => array(
					'open'        => __( 'Ask a question', 'ai-faq-chatbot' ),
					'close'       => __( 'Close chat', 'ai-faq-chatbot' ),
					'placeholder' => __( 'Type your question…', 'ai-faq-chatbot' ),
					'send'        => __( 'Send', 'ai-faq-chatbot' ),
					'thinking'    => __( 'Looking it up…', 'ai-faq-chatbot' ),
					'sources'     => __( 'Sources', 'ai-faq-chatbot' ),
					'contact'     => __( 'Contact us', 'ai-faq-chatbot' ),
					'error'       => __( 'Sorry, something went wrong. Please try again, or contact us.', 'ai-faq-chatbot' ),
					'note'        => __( 'Answers come only from this website and may be incomplete.', 'ai-faq-chatbot' ),
				),
			)
		);
		wp_enqueue_style( 'aifaq-widget' );
		wp_enqueue_script( 'aifaq-widget' );
	}

	/**
	 * Inline chat for the shortcode.
	 *
	 * @return string
	 */
	public function shortcode(): string {
		if ( ! $this->active() ) {
			return '';
		}
		$this->inline = true;
		return '<div class="aifaq-chat aifaq-chat--inline" data-aifaq-mode="inline"></div>';
	}

	/**
	 * Floating button, unless the page has the inline chat.
	 */
	public function render_floating(): void {
		if ( ! $this->active() || $this->inline || is_admin() ) {
			return;
		}
		echo '<div class="aifaq-chat aifaq-chat--floating" data-aifaq-mode="floating"></div>';
	}
}
