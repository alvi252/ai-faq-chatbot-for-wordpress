<?php
/**
 * "I don't know" messages.
 *
 * @package AIFAQ
 */

namespace AIFAQ\Core\Answer;

defined( 'ABSPATH' ) || exit;

/**
 * The canned refusal in the visitor's language. Used whenever the bot does not
 * answer, so a manipulated model reply is never shown in its place.
 */
final class Refusals {

	private const MESSAGES = array(
		'en' => "I don't know based on this website's pages. Try rephrasing your question, or contact us.",
		'fr' => "Je ne sais pas d'après les pages de ce site. Essayez de reformuler votre question ou contactez-nous.",
		'de' => 'Das kann ich anhand der Seiten dieser Website nicht beantworten. Formulieren Sie die Frage bitte um oder kontaktieren Sie uns.',
		'es' => 'No lo sé según las páginas de este sitio. Intente reformular su pregunta o contacte con nosotros.',
		'it' => 'Non lo so in base alle pagine di questo sito. Prova a riformulare la domanda o contattaci.',
		'nl' => 'Dat weet ik niet op basis van de pagina\'s van deze website. Probeer uw vraag anders te formuleren of neem contact met ons op.',
		'pt' => 'Não sei com base nas páginas deste site. Tente reformular a pergunta ou contacte-nos.',
	);

	private const HINTS = array(
		'en' => 'the is are what how do does can i my you your to for of and when where which with',
		'fr' => 'le la les est sont quel quelle quels comment je mon ma mes vous votre pour des et quand où combien puis-je un une du ai ce avec pas',
		'de' => 'der die das ist sind wie was ich mein meine sie ihr für und wann wo kann ein eine nicht zu mit',
		'es' => 'el los las es son qué cómo yo mi mis usted su para y cuándo dónde puedo un una cuánto con',
		'it' => 'il lo gli è sono come cosa io mio mia lei per e quando dove posso un una quanto con',
		'nl' => 'de het is zijn wat hoe ik mijn u uw voor en wanneer waar kan een niet met',
		'pt' => 'o os as é são como quanto eu meu minha você para e quando onde posso um uma não com do da',
	);

	/**
	 * Refusal in the language of the question.
	 *
	 * @param string $question Question.
	 * @return string
	 */
	public static function message_for( string $question ): string {
		return self::MESSAGES[ self::language( $question ) ];
	}

	/**
	 * Cheap function-word vote; English when nothing matches.
	 *
	 * @param string $text Text.
	 * @return string Language code.
	 */
	public static function language( string $text ): string {
		preg_match_all( "/[\\p{L}\\p{N}'-]+/u", mb_strtolower( $text ), $matches );
		$best       = 'en';
		$best_score = 0;
		foreach ( self::HINTS as $language => $hints ) {
			$vocabulary = array_fill_keys( explode( ' ', $hints ), true );
			$score      = 0;
			foreach ( $matches[0] as $word ) {
				$score += isset( $vocabulary[ $word ] ) ? 1 : 0;
			}
			if ( $score > $best_score ) {
				$best       = $language;
				$best_score = $score;
			}
		}
		return $best;
	}
}
