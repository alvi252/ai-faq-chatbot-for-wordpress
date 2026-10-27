<?php
/**
 * Settings → FAQ Chatbot.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Admin;

use AIFAQ\Core\Answer\AnswerResult;
use AIFAQ\Settings;
use AIFAQ\Wp\CallLog;
use AIFAQ\Wp\Chat;
use AIFAQ\Wp\ContentSource;
use AIFAQ\Wp\IndexScheduler;
use AIFAQ\Wp\PassageRepository;
use AIFAQ\Wp\QuestionLog;

defined( 'ABSPATH' ) || exit;

/**
 * Four tabs: Settings, Index (rebuild and a test question), Questions (what
 * visitors asked, unanswered first) and Usage (tokens and estimated cost).
 */
final class AdminPage {

	public const SLUG          = 'ai-faq-chatbot';
	public const CAPABILITY    = 'manage_options';
	public const NOTICE_PREFIX = 'aifaq_notice_';

	/**
	 * Constructor.
	 *
	 * @param Settings          $settings  Settings.
	 * @param PassageRepository $passages  Passages.
	 * @param ContentSource     $content   Content.
	 * @param IndexScheduler    $scheduler Scheduler.
	 * @param Chat              $chat      Chat.
	 * @param QuestionLog       $questions Question log.
	 * @param CallLog           $calls     Call log.
	 */
	public function __construct(
		private Settings $settings,
		private PassageRepository $passages,
		private ContentSource $content,
		private IndexScheduler $scheduler,
		private Chat $chat,
		private QuestionLog $questions,
		private CallLog $calls
	) {
	}

	/**
	 * Registers hooks.
	 */
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_aifaq_rebuild', array( $this, 'handle_rebuild' ) );
		add_action( 'admin_post_aifaq_test', array( $this, 'handle_test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AIFAQ_PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Adds the page under Settings.
	 */
	public function add_menu(): void {
		add_options_page( __( 'FAQ Chatbot', 'ai-faq-chatbot' ), __( 'FAQ Chatbot', 'ai-faq-chatbot' ), self::CAPABILITY, self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * "Settings" link on the Plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ai-faq-chatbot' ) . '</a>' );
		return $links;
	}

	/**
	 * Page URL.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	public static function url( string $tab = 'settings' ): string {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Renders the page.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Tab switch only.
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		$tabs = array(
			'settings'  => __( 'Settings', 'ai-faq-chatbot' ),
			'index'     => __( 'Index', 'ai-faq-chatbot' ),
			'questions' => __( 'Questions', 'ai-faq-chatbot' ),
			'usage'     => __( 'Usage', 'ai-faq-chatbot' ),
		);
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'settings';

		echo '<div class="wrap"><h1>' . esc_html__( 'FAQ Chatbot', 'ai-faq-chatbot' ) . '</h1>';
		if ( ! $this->settings->is_configured() ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Add an OpenAI API key to start. Until then the chat window is hidden from visitors.', 'ai-faq-chatbot' ) . '</p></div>';
		}
		$unpriced = $this->settings->unpriced_models();
		if ( array() !== $unpriced ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: model IDs. */
						__( 'The chat is paused: %s has no price in the price table, so the daily budget cannot be counted. Add its price on the Settings tab, or set the daily budget to 0 to run without one.', 'ai-faq-chatbot' ),
						implode( ', ', $unpriced )
					)
				)
			);
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( self::url( $key ) ), $key === $tab ? ' nav-tab-active' : '', esc_html( $label ) );
		}
		echo '</nav>';

		switch ( $tab ) {
			case 'index':
				$this->render_index();
				break;
			case 'questions':
				$this->render_questions();
				break;
			case 'usage':
				$this->render_usage();
				break;
			default:
				$this->render_settings();
		}
		echo '</div>';
	}

	/**
	 * Settings tab.
	 */
	private function render_settings(): void {
		$s    = $this->settings->all();
		$name = Settings::OPTION;

		settings_errors( Settings::OPTION );
		echo '<form method="post" action="options.php">';
		settings_fields( Settings::GROUP );
		echo '<table class="form-table" role="presentation">';

		$this->row( __( 'Chat on the site', 'ai-faq-chatbot' ), sprintf( '<label><input type="checkbox" name="%s[enabled]" value="1" %s> %s</label>', esc_attr( $name ), checked( ! empty( $s['enabled'] ), true, false ), esc_html__( 'Show the chat button on every page', 'ai-faq-chatbot' ) ), __( 'Or put [aifaq_chatbot] on one page for an inline chat.', 'ai-faq-chatbot' ) );

		if ( $this->settings->key_from_constant() ) {
			$key_field = esc_html__( 'Set in wp-config.php (AIFAQ_OPENAI_API_KEY).', 'ai-faq-chatbot' );
		} else {
			$saved     = '' !== (string) $s['openai_api_key'];
			$key_field = sprintf( '<input type="password" class="regular-text" autocomplete="new-password" name="%s[openai_api_key]" value="" placeholder="%s">', esc_attr( $name ), esc_attr( $saved ? __( 'Saved. Leave blank to keep it.', 'ai-faq-chatbot' ) : 'sk-...' ) );
			if ( $saved ) {
				$key_field .= sprintf( ' <label><input type="checkbox" name="%s[openai_api_key_delete]" value="1"> %s</label>', esc_attr( $name ), esc_html__( 'Delete the saved key', 'ai-faq-chatbot' ) );
			}
		}
		$this->row( __( 'OpenAI API key', 'ai-faq-chatbot' ), $key_field, __( 'Safer: define AIFAQ_OPENAI_API_KEY in wp-config.php. Set a monthly spending limit in your OpenAI account too.', 'ai-faq-chatbot' ) );

		$this->row( __( 'Answer model', 'ai-faq-chatbot' ), $this->input( 'chat_model', (string) $s['chat_model'] ) );
		$this->row( __( 'Embedding model', 'ai-faq-chatbot' ), $this->input( 'embedding_model', (string) $s['embedding_model'] ) . ' ' . $this->input( 'dimensions', (string) $s['dimensions'], 'number', 'small-text' ), __( 'Vector length: 512 keeps the index small. Changing either needs a rebuild.', 'ai-faq-chatbot' ) );

		$types = '';
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) {
			if ( 'attachment' === $type->name ) {
				continue;
			}
			$types .= sprintf( '<label style="margin-right:12px"><input type="checkbox" name="%s[post_types][]" value="%s" %s> %s</label>', esc_attr( $name ), esc_attr( $type->name ), checked( in_array( $type->name, (array) $s['post_types'], true ), true, false ), esc_html( $type->labels->name ) );
		}
		$this->row( __( 'Answer from', 'ai-faq-chatbot' ), $types, __( 'Only published, public, not password-protected content is indexed.', 'ai-faq-chatbot' ) );
		$this->row( __( 'Never use these IDs', 'ai-faq-chatbot' ), $this->input( 'exclude_ids', (string) $s['exclude_ids'] ), __( 'Comma-separated post or page IDs, e.g. a terms page you do not want quoted.', 'ai-faq-chatbot' ) );
		$this->row( __( 'Relevance gate', 'ai-faq-chatbot' ), $this->input( 'min_similarity', (string) $s['min_similarity'], 'number', 'small-text', 'step="0.01" min="0" max="0.9"' ) . ' ' . esc_html__( 'passages per answer', 'ai-faq-chatbot' ) . ' ' . $this->input( 'top_k', (string) $s['top_k'], 'number', 'small-text', 'min="1" max="10"' ), __( 'Below this similarity the bot says "I don\'t know" without asking the model.', 'ai-faq-chatbot' ) );

		$this->row( __( 'Business name', 'ai-faq-chatbot' ), $this->input( 'site_name', (string) $s['site_name'] ), __( 'Used in the model\'s instructions. Empty: the site title.', 'ai-faq-chatbot' ) );
		$this->row( __( 'Chat title', 'ai-faq-chatbot' ), $this->input( 'title', (string) $s['title'] ) );
		$this->row( __( 'Welcome message', 'ai-faq-chatbot' ), sprintf( '<textarea class="large-text" rows="2" name="%s[welcome]">%s</textarea>', esc_attr( $name ), esc_textarea( (string) $s['welcome'] ) ) );
		$this->row( __( 'Contact page', 'ai-faq-chatbot' ), $this->input( 'contact_url', (string) $s['contact_url'], 'url' ), __( 'Linked under "I don\'t know" replies.', 'ai-faq-chatbot' ) );
		$this->row( __( 'Colour', 'ai-faq-chatbot' ), $this->input( 'accent', (string) $s['accent'], 'color', '' ) );

		$this->row( __( 'Limits', 'ai-faq-chatbot' ), $this->input( 'per_visitor_hour', (string) $s['per_visitor_hour'], 'number', 'small-text', 'min="0"' ) . ' ' . esc_html__( 'questions per visitor per hour', 'ai-faq-chatbot' ) . '<br>' . $this->input( 'daily_budget', (string) $s['daily_budget'], 'number', 'small-text', 'step="0.01" min="0"' ) . ' ' . esc_html__( 'USD per day, estimated; the chat pauses until midnight UTC when reached (0 = no limit)', 'ai-faq-chatbot' ) );
		$this->row( __( 'Question log', 'ai-faq-chatbot' ), sprintf( '<label><input type="checkbox" name="%s[log_questions]" value="1" %s> %s</label> ', esc_attr( $name ), checked( ! empty( $s['log_questions'] ), true, false ), esc_html__( 'Keep visitors\' questions for', 'ai-faq-chatbot' ) ) . $this->input( 'keep_days', (string) $s['keep_days'], 'number', 'small-text', 'min="1" max="365"' ) . ' ' . esc_html__( 'days', 'ai-faq-chatbot' ), __( 'Only the question text, the outcome and the pages cited. No IP addresses or accounts.', 'ai-faq-chatbot' ) );
		$this->row( __( 'Timeout', 'ai-faq-chatbot' ), $this->input( 'timeout', (string) $s['timeout'], 'number', 'small-text', 'min="5" max="120"' ) . ' ' . esc_html__( 'seconds', 'ai-faq-chatbot' ) );
		$this->row( __( 'Prices', 'ai-faq-chatbot' ), sprintf( '<textarea class="large-text code" rows="6" name="%s[price_table]">%s</textarea>', esc_attr( $name ), esc_textarea( (string) $s['price_table'] ) ), __( 'USD per million tokens, for the cost estimates and the daily budget. While a daily budget is set, a model without a price here pauses the chat. Check your provider\'s current prices.', 'ai-faq-chatbot' ) );

		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/**
	 * Index tab.
	 */
	private function render_index(): void {
		$rows     = $this->passages->per_post();
		$progress = $this->scheduler->progress();
		$total    = array_sum( array_column( $rows, 'passages' ) );
		$model    = (string) $this->settings->get( 'embedding_model' );
		$dims     = $this->settings->int( 'dimensions' );
		$stale    = array_filter( $rows, static fn( array $r ): bool => $r['model'] !== $model || $r['dimensions'] !== $dims );

		$this->pending_notice();

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of pages, 2: number of passages. */
					__( '%1$d pages indexed, %2$d passages.', 'ai-faq-chatbot' ),
					count( $rows ),
					$total
				)
			)
		);
		if ( null !== $progress ) {
			printf( '<div class="notice notice-info inline"><p>%s</p></div>', esc_html( sprintf( /* translators: 1: remaining, 2: total. */ __( 'Rebuild running in the background: %1$d of %2$d pages left. Reload to see progress.', 'ai-faq-chatbot' ), $progress['remaining'], $progress['total'] ) ) );
		}
		if ( array() !== $stale ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some pages were indexed with another embedding model or vector length and are not searched. Rebuild the index.', 'ai-faq-chatbot' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:12px 0">';
		wp_nonce_field( 'aifaq_rebuild' );
		echo '<input type="hidden" name="action" value="aifaq_rebuild">';
		echo '<label><input type="checkbox" name="force" value="1"> ' . esc_html__( 'Re-embed unchanged pages too', 'ai-faq-chatbot' ) . '</label> ';
		submit_button( __( 'Rebuild index', 'ai-faq-chatbot' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html__( 'Pages are also indexed a few seconds after you publish or update them.', 'ai-faq-chatbot' ) . '</p>';

		echo '<h2>' . esc_html__( 'Try a question', 'ai-faq-chatbot' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'aifaq_test' );
		echo '<input type="hidden" name="action" value="aifaq_test">';
		echo '<input type="text" class="large-text" name="question" maxlength="' . esc_attr( (string) Chat::MAX_LENGTH ) . '" placeholder="' . esc_attr__( 'How long does delivery take?', 'ai-faq-chatbot' ) . '"> ';
		submit_button( __( 'Ask', 'ai-faq-chatbot' ), 'primary', 'submit', false );
		echo '</form>';
		$this->render_test_result();

		echo '<h2>' . esc_html__( 'Indexed pages', 'ai-faq-chatbot' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Passages', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Indexed (UTC)', 'ai-faq-chatbot' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td><a href="%s">%s</a></td><td>%d</td><td>%s</td></tr>', esc_url( $row['url'] ), esc_html( $row['title'] ), (int) $row['passages'], esc_html( $row['indexed_at'] ) );
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="3">' . esc_html__( 'Nothing indexed yet. Rebuild the index.', 'ai-faq-chatbot' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Result of the last test question, kept for one page load.
	 */
	private function render_test_result(): void {
		$key  = 'aifaq_test_' . get_current_user_id();
		$test = get_transient( $key );
		if ( ! is_array( $test ) ) {
			return;
		}
		delete_transient( $key );

		echo '<div class="card" style="max-width:none">';
		printf( '<p><strong>%s</strong> %s</p>', esc_html__( 'Question:', 'ai-faq-chatbot' ), esc_html( (string) $test['question'] ) );
		printf( '<p><strong>%s</strong> %s</p>', esc_html( (string) $test['status'] ), esc_html( (string) $test['answer'] ) );
		foreach ( (array) $test['sources'] as $source ) {
			printf( '<p>[%d] <a href="%s">%s</a></p>', (int) $source['n'], esc_url( (string) $source['url'] ), esc_html( (string) $source['title'] . ( '' !== (string) $source['heading'] ? ' › ' . $source['heading'] : '' ) ) );
		}
		if ( array() !== (array) $test['retrieved'] ) {
			echo '<details><summary>' . esc_html__( 'Passages sent to the model', 'ai-faq-chatbot' ) . '</summary><ol>';
			foreach ( (array) $test['retrieved'] as $passage ) {
				printf( '<li>%s (%s)<br><small>%s</small></li>', esc_html( (string) $passage['label'] ), esc_html( number_format( (float) $passage['similarity'], 2 ) ), esc_html( (string) $passage['text'] ) );
			}
			echo '</ol></details>';
		}
		printf( '<p class="description">%s</p>', esc_html( (string) $test['meta'] ) );
		echo '</div>';
	}

	/**
	 * Questions tab.
	 */
	private function render_questions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Filter only.
		$all    = isset( $_GET['all'] );
		$rows   = $this->questions->recent( ! $all );
		$counts = $this->questions->counts();

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: answered, 2: not answered, 3: errors. */
					__( 'Last 30 days: %1$d answered, %2$d "I don\'t know", %3$d errors.', 'ai-faq-chatbot' ),
					$counts[ AnswerResult::ANSWERED ] ?? 0,
					$counts[ AnswerResult::REFUSED ] ?? 0,
					$counts[ AnswerResult::ERROR ] ?? 0
				)
			)
		);
		printf(
			'<p><a href="%s">%s</a> | <a href="%s">%s</a></p>',
			esc_url( self::url( 'questions' ) ),
			esc_html__( 'Not answered', 'ai-faq-chatbot' ),
			esc_url( add_query_arg( 'all', '1', self::url( 'questions' ) ) ),
			esc_html__( 'All', 'ai-faq-chatbot' )
		);
		echo '<p class="description">' . esc_html__( 'A question the bot could not answer usually means the site does not say it yet. Add it to your FAQ page; the index updates on save.', 'ai-faq-chatbot' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'When (UTC)', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Question', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Outcome', 'ai-faq-chatbot' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( (string) $row['created_at'] ), esc_html( (string) $row['question'] ), esc_html( (string) $row['status'] . ( '' !== (string) $row['reason'] ? ' (' . $row['reason'] . ')' : '' ) ) );
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No questions yet.', 'ai-faq-chatbot' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Usage tab.
	 */
	private function render_usage(): void {
		$rows   = $this->calls->summary( 30 );
		$budget = (float) $this->settings->get( 'daily_budget' );

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: spent today, 2: daily budget. */
					__( 'Today (UTC): $%1$s of a $%2$s daily budget, estimated from the price table.', 'ai-faq-chatbot' ),
					number_format( $this->calls->spent_today(), 4 ),
					$budget > 0 ? number_format( $budget, 2 ) : '∞'
				)
			)
		);
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Kind', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Model', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Calls', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Errors', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Tokens in / out', 'ai-faq-chatbot' ) . '</th><th>' . esc_html__( 'Estimated cost', 'ai-faq-chatbot' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d / %d</td><td>%s</td></tr>',
				esc_html( (string) $row['kind'] ),
				esc_html( (string) $row['model'] ),
				(int) $row['calls'],
				(int) $row['errors'],
				(int) $row['input_tokens'],
				(int) $row['output_tokens'],
				esc_html( null === $row['cost'] ? '-' : '$' . number_format( (float) $row['cost'], 4 ) . ( (int) $row['unpriced'] > 0 ? ' +' : '' ) )
			);
		}
		if ( array() === $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No calls in the last 30 days.', 'ai-faq-chatbot' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Starts a rebuild.
	 */
	public function handle_rebuild(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ai-faq-chatbot' ) );
		}
		check_admin_referer( 'aifaq_rebuild' );

		$ids     = $this->content->indexable_ids();
		$removed = $this->passages->delete_except( $ids );
		$counts  = $this->scheduler->start_rebuild( $ids, ! empty( $_POST['force'] ) );

		$message = sprintf(
			/* translators: 1: pages queued, 2: counts of the first batch, 3: pages removed. */
			__( '%1$d pages to index. First batch: %2$s. Removed from the index: %3$d.', 'ai-faq-chatbot' ),
			count( $ids ),
			array() === $counts ? '-' : implode( ', ', array_map( static fn( string $k, int $v ): string => $k . ' ' . $v, array_keys( $counts ), $counts ) ),
			$removed
		);
		set_transient( self::NOTICE_PREFIX . get_current_user_id(), $message, 300 );
		wp_safe_redirect( self::url( 'index' ) );
		exit;
	}

	/**
	 * Answers a test question from the admin.
	 */
	public function handle_test(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ai-faq-chatbot' ) );
		}
		check_admin_referer( 'aifaq_test' );

		$question = isset( $_POST['question'] ) ? sanitize_text_field( wp_unslash( $_POST['question'] ) ) : '';
		$reply    = $this->chat->ask( $question, null, false );
		$result   = $reply['result'];

		$retrieved = array();
		foreach ( null === $result ? array() : $result->retrieved as $hit ) {
			$retrieved[] = array(
				'label'      => $hit->passage->title . ( '' !== $hit->passage->heading ? ' › ' . $hit->passage->heading : '' ),
				'similarity' => $hit->similarity,
				'text'       => mb_substr( $hit->passage->text, 0, 300 ),
			);
		}
		$meta = null === $result ? '' : sprintf(
			/* translators: 1: reason, 2: best similarity, 3: milliseconds, 4: cost. */
			__( 'Reason: %1$s. Best similarity: %2$s. Time: %3$d ms. Estimated cost: %4$s.', 'ai-faq-chatbot' ),
			'' === $result->reason ? '-' : $result->reason,
			number_format( $result->best_similarity, 2 ),
			$result->latency_ms,
			null === $result->cost() ? '-' : '$' . number_format( (float) $result->cost(), 6 )
		);

		set_transient(
			'aifaq_test_' . get_current_user_id(),
			array(
				'question'  => $question,
				'status'    => (string) $reply['body']['status'],
				'answer'    => (string) $reply['body']['answer'],
				'sources'   => $reply['body']['sources'],
				'retrieved' => $retrieved,
				'meta'      => $meta,
			),
			300
		);
		wp_safe_redirect( self::url( 'index' ) );
		exit;
	}

	/**
	 * Shows, once, the message the last rebuild left for this user. Kept in a
	 * transient rather than the URL, so a link cannot put text on this screen.
	 */
	private function pending_notice(): void {
		$key     = self::NOTICE_PREFIX . get_current_user_id();
		$message = get_transient( $key );
		if ( is_string( $message ) && '' !== $message ) {
			delete_transient( $key );
			printf( '<div class="notice notice-success inline"><p>%s</p></div>', esc_html( $message ) );
		}
	}

	/**
	 * Form table row.
	 *
	 * @param string $label       Label.
	 * @param string $field       Field HTML (already escaped).
	 * @param string $description Description.
	 */
	private function row( string $label, string $field, string $description = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
		echo $field; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Input field HTML.
	 *
	 * @param string $key   Setting key.
	 * @param string $value Value.
	 * @param string $type  Input type.
	 * @param string $css_class CSS class.
	 * @param string $extra Extra attributes (trusted, literal).
	 * @return string
	 */
	private function input( string $key, string $value, string $type = 'text', string $css_class = 'regular-text', string $extra = '' ): string {
		return sprintf( '<input type="%s" class="%s" name="%s[%s]" value="%s" %s>', esc_attr( $type ), esc_attr( $css_class ), esc_attr( Settings::OPTION ), esc_attr( $key ), esc_attr( $value ), $extra );
	}
}
