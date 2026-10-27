<?php
/**
 * Plugin bootstrap.
 *
 * @package AIFAQ
 */

namespace AIFAQ;

use AIFAQ\Admin\AdminPage;
use AIFAQ\Core\Guard\RateLimiter;
use AIFAQ\Wp\CallLog;
use AIFAQ\Wp\Chat;
use AIFAQ\Wp\Cli;
use AIFAQ\Wp\ContentSource;
use AIFAQ\Wp\Indexer;
use AIFAQ\Wp\IndexScheduler;
use AIFAQ\Wp\PassageRepository;
use AIFAQ\Wp\QuestionLog;
use AIFAQ\Wp\RestController;
use AIFAQ\Wp\Services;
use AIFAQ\Wp\TransientCounterStore;
use AIFAQ\Wp\Widget;
use AIFAQ\Wp\WpTransport;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the services together and registers hooks.
 */
final class Plugin {

	/**
	 * Whether boot() ran.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Boots the plugin. Hooked to plugins_loaded.
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		$settings  = new Settings();
		$passages  = new PassageRepository();
		$calls     = new CallLog();
		$questions = new QuestionLog();
		$services  = new Services( $settings, $passages, new WpTransport() );
		$content   = new ContentSource( $settings );
		$indexer   = new Indexer( $settings, $content, $passages, $calls, $services );
		$scheduler = new IndexScheduler( $indexer );
		$chat      = new Chat( $settings, $services, $calls, $questions, new RateLimiter( new TransientCounterStore(), $settings->int( 'per_visitor_hour' ) ) );

		add_action( 'init', array( $settings, 'register' ) );
		add_action( 'init', array( Installer::class, 'maybe_upgrade' ) );
		add_action( 'init', array( self::class, 'load_textdomain' ) );

		$scheduler->register_hooks();
		// Indexed types or excluded IDs changed: drop what no longer belongs now, not at the next rebuild.
		add_action( 'update_option_' . Settings::OPTION, array( $indexer, 'prune' ) );
		( new RestController( $chat ) )->register_hooks();
		( new Widget( $settings ) )->register_hooks();

		// Daily cleanup: old questions and old call rows.
		add_action(
			QuestionLog::PRUNE_HOOK,
			static function () use ( $settings, $questions, $calls ): void {
				$questions->prune( $settings->int( 'keep_days' ) );
				$calls->prune();
			}
		);
		add_action(
			'init',
			static function (): void {
				if ( false === wp_next_scheduled( QuestionLog::PRUNE_HOOK ) ) {
					wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', QuestionLog::PRUNE_HOOK );
				}
			}
		);

		if ( is_admin() ) {
			( new AdminPage( $settings, $passages, $content, $scheduler, $chat, $questions, $calls ) )->register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$cli = new Cli( $settings, $content, $passages, $indexer, $chat );
			\WP_CLI::add_command( 'aifaq index', array( $cli, 'index' ) );
			\WP_CLI::add_command( 'aifaq ask', array( $cli, 'ask' ) );
			\WP_CLI::add_command( 'aifaq eval', array( $cli, 'evaluate' ) );
		}
	}

	/**
	 * Loads translations.
	 */
	public static function load_textdomain(): void {
		load_plugin_textdomain( 'ai-faq-chatbot', false, dirname( plugin_basename( AIFAQ_PLUGIN_FILE ) ) . '/languages' );
	}
}
