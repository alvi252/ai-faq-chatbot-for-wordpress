<?php
/**
 * Activation and database upgrades.
 *
 * @package AIFAQ
 */

namespace AIFAQ;

use AIFAQ\Wp\CallLog;
use AIFAQ\Wp\IndexScheduler;
use AIFAQ\Wp\PassageRepository;
use AIFAQ\Wp\QuestionLog;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the tables.
 */
final class Installer {

	public const DB_VERSION        = '1';
	public const DB_VERSION_OPTION = 'aifaq_db_version';

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::install();
	}

	/**
	 * Deactivation hook: scheduled indexing and cleanup would fail without the plugin.
	 */
	public static function deactivate(): void {
		// wp_unschedule_hook(), not wp_clear_scheduled_hook(): the per-post events carry the post ID as an argument.
		wp_unschedule_hook( IndexScheduler::BATCH_HOOK );
		wp_unschedule_hook( IndexScheduler::POST_HOOK );
		wp_unschedule_hook( QuestionLog::PRUNE_HOOK );
	}

	/**
	 * Runs the installer when the stored database version is outdated.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::DB_VERSION_OPTION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Creates or updates the tables.
	 */
	private static function install(): void {
		PassageRepository::create_table();
		CallLog::create_table();
		QuestionLog::create_table();
		// Autoloaded: it is read on every request by maybe_upgrade().
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}
}
