<?php
/**
 * Plugin Name: PAP Texturize
 * Plugin URI: https://github.com/gitlost/pap-texturize
 * Description: Patch-as-plugin that texturizes text containing inline HTML tags.
 * Version: 1.0.0
 * Author: gitlost
 * Author URI: https://profiles.wordpress.org/gitlost
 * License: GPLv2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Called on 'init' action.
 * Replace wptexturize() with pap_wptexturize().
 */
function pap_texturize_init() {
	// Need lower priority to keep before other filters already added after wptexturize(). Not quite equivalent though.
	$priority = 9;

	foreach ( array( 'comment_author', 'term_name', 'link_name', 'link_description', 'link_notes', 'bloginfo', 'wp_title', 'widget_title' ) as $filter ) {
		remove_filter( $filter, 'wptexturize' );
		add_filter( $filter, 'pap_wptexturize', $priority );
	}

	foreach ( array( 'single_post_title', 'single_cat_title', 'single_tag_title', 'single_month_title', 'nav_menu_attr_title', 'nav_menu_description' ) as $filter ) {
		remove_filter( $filter, 'wptexturize' );
		add_filter( $filter, 'pap_wptexturize', $priority );
	}

	foreach ( array( 'term_description' ) as $filter ) {
		remove_filter( $filter, 'wptexturize' );
		add_filter( $filter, 'pap_wptexturize', $priority );
	}

	remove_filter( 'the_title', 'wptexturize' );
	add_filter( 'the_title', 'pap_wptexturize', $priority );

	remove_filter( 'the_content', 'wptexturize' );
	add_filter( 'the_content', 'pap_wptexturize', $priority );

	remove_filter( 'the_excerpt', 'wptexturize' );
	add_filter( 'the_excerpt', 'pap_wptexturize', $priority );

	remove_filter( 'the_post_thumbnail_caption', 'wptexturize' );
	add_filter( 'the_post_thumbnail_caption', 'pap_wptexturize', $priority );

	remove_filter( 'comment_text', 'wptexturize' );
	add_filter( 'comment_text', 'pap_wptexturize', $priority );

	remove_filter( 'list_cats', 'wptexturize' );
	add_filter( 'list_cats', 'pap_wptexturize', $priority );

	remove_filter( 'the_excerpt_embed', 'wptexturize' );
	add_filter( 'the_excerpt_embed', 'pap_wptexturize', $priority );

	// wptexturize() called directly by wp_get_document_title() and get_archives_link() in "wp-includes/general-template.php",
	// by gallery_shortcode() in "wp-includes/media.php", by WP_Theme::markup_header() in "wp-includes/class-wp-theme.php", and
	// by trackback_rdf() in "wp-includes/comment-template.php".
	// Can't really do much about them, except for wp_get_document_title(), but would need to disable wptexturize(), which would screw up the others.
	//add_filter( 'document_title_parts', 'pap_wptexturize_document_title_parts' );
}
add_action( 'init', 'pap_texturize_init' );

/**
 * NOT called on 'document_title_parts' filter.
 * Texturize title parts.
 */
function pap_wptexturize_document_title_parts( $title ) {
	if ( isset( $title['title'] ) ) {
		$title['title'] = pap_wptexturize( $title['title'] );
	}
	if ( isset( $title['page'] ) ) {
		$title['page'] = pap_wptexturize( $title['page'] );
	}
	if ( isset( $title['tagline'] ) ) {
		$title['tagline'] = pap_wptexturize( $title['tagline'] );
	}
	if ( isset( $title['site'] ) ) {
		$title['site'] = pap_wptexturize( $title['site'] );
	}
	return $title;
}

/**
 * Replaces common plain text characters into formatted entities
 *
 * As an example,
 *
 *     'cause today's effort makes it worth tomorrow's "holiday" ...
 *
 * Becomes:
 *
 *     &#8217;cause today&#8217;s effort makes it worth tomorrow&#8217;s &#8220;holiday&#8221; &#8230;
 *
 * Code within certain html blocks are skipped.
 *
 * Do not use this function before the {@see 'init'} action hook; everything will break.
 *
 * @since 0.71
 *
 * @global array $wp_cockneyreplace Array of formatted entities for certain common phrases
 * @global array $shortcode_tags
 * @staticvar array $static_characters
 * @staticvar array $static_replacements
 * @staticvar array $dynamic_characters
 * @staticvar array $dynamic_replacements
 * @staticvar array $default_no_texturize_tags
 * @staticvar array $default_no_texturize_shortcodes
 * @staticvar bool  $run_texturize
 *
 * @param string $text The text to be formatted
 * @param bool   $reset Set to true for unit testing. Translated patterns will reset.
 * @return string The string replaced with html entities
 */
function pap_wptexturize( $text, $reset = false ) {
	global $wp_cockneyreplace, $shortcode_tags;
	static $static_characters = null,
		$static_replacements = null,
		$dynamic_characters_embed = null,
		$dynamic_replacements_embed = null,
		$dynamic_characters = null,
		$dynamic_replacements = null,
		$default_no_texturize_tags = null,
		$default_no_texturize_shortcodes = null,
		$run_texturize = true,
		$apos = null,
		$prime = null,
		$double_prime = null,
		$opening_quote = null,
		$closing_quote = null,
		$opening_single_quote = null,
		$closing_single_quote = null,
		$apos_flag, $open_sq_flag, $open_q_flag, $close_sq_flag, $close_q_flag, $prime_sq_flag, $prime_q_flag, $sq_flag, $q_flag, $primes_flag,
		$flags_sq, $flags_q, $reals_sq, $reals_q,
		$spaces;

	// If there's nothing to do, just stop.
	if ( empty( $text ) || false === $run_texturize ) {
		return $text;
	}

	// Set up static variables. Run once only.
	if ( $reset || ! isset( $static_characters ) ) {
		/**
		 * Filters whether to skip running pap_wptexturize().
		 *
		 * Passing false to the filter will effectively short-circuit pap_wptexturize().
		 * returning the original text passed to the function instead.
		 *
		 * The filter runs only once, the first time pap_wptexturize() is called.
		 *
		 * @since 4.0.0
		 *
		 * @see pap_wptexturize()
		 *
		 * @param bool $run_texturize Whether to short-circuit pap_wptexturize().
		 */
		$run_texturize = apply_filters( 'run_wptexturize', $run_texturize );
		if ( false === $run_texturize ) {
			return $text;
		}

		/* translators: opening curly double quote */
		$opening_quote = _x( '&#8220;', 'opening curly double quote' );
		/* translators: closing curly double quote */
		$closing_quote = _x( '&#8221;', 'closing curly double quote' );

		/* translators: apostrophe, for example in 'cause or can't */
		$apos = _x( '&#8217;', 'apostrophe' );

		/* translators: prime, for example in 9' (nine feet) */
		$prime = _x( '&#8242;', 'prime' );
		/* translators: double prime, for example in 9" (nine inches) */
		$double_prime = _x( '&#8243;', 'double prime' );

		/* translators: opening curly single quote */
		$opening_single_quote = _x( '&#8216;', 'opening curly single quote' );
		/* translators: closing curly single quote */
		$closing_single_quote = _x( '&#8217;', 'closing curly single quote' );

		/* translators: en dash */
		$en_dash = _x( '&#8211;', 'en dash' );
		/* translators: em dash */
		$em_dash = _x( '&#8212;', 'em dash' );

		// Standardize size of flags to max of primes/quotes manipulated by pap_wptexturize_primes().
		// This will allow pap_wptexturize_primes() to do its replacements without worrying about offsets changing.
		$flag_len = max( 5, strlen( $closing_quote ), strlen( $prime ), strlen( $double_prime ), strlen( $closing_single_quote ) );

		$apos_flag = str_pad( '<i a>', $flag_len, '>' );
		$open_sq_flag = str_pad( '<i o>', $flag_len, '>' );
		$close_sq_flag = str_pad( '<i c>', $flag_len, '>' );
		$prime_sq_flag = str_pad( '<i p>', $flag_len, '>' );
		$prime_q_flag = str_pad( '<i P>', $flag_len, '>' );
		$open_q_flag = str_pad( '<i O>', $flag_len, '>' );
		$close_q_flag = str_pad( '<i C>', $flag_len, '>' );
		$sq_flag = str_repeat( "'", $flag_len );
		$q_flag = str_repeat( '"', $flag_len );
		$primes_flag = str_pad( '<i f>', $flag_len, '>' );

		// Flags & reals arrays - used to reinstate the real values.
		$flags_sq = array( $sq_flag, $prime_sq_flag, $open_sq_flag, $close_sq_flag, $apos_flag );
		$reals_sq = array( "'", $prime, $opening_single_quote, $closing_single_quote, $apos );
		$flags_q = array( $q_flag, $prime_q_flag, $open_q_flag, $close_q_flag );
		$reals_q = array( '"', $double_prime, $opening_quote, $closing_quote );

		$default_no_texturize_tags = array('pre', 'code', 'kbd', 'style', 'script', 'tt');
		$default_no_texturize_shortcodes = array('code');

		// if a plugin has provided an autocorrect array, use it
		if ( isset($wp_cockneyreplace) ) {
			$cockney = array_keys( $wp_cockneyreplace );
			$cockneyreplace = array_values( $wp_cockneyreplace );
		} else {
			/* translators: This is a comma-separated list of words that defy the syntax of quotations in normal use,
			 * for example...  'We do not have enough words yet' ... is a typical quoted phrase.  But when we write
			 * lines of code 'til we have enough of 'em, then we need to insert apostrophes instead of quotes.
			 */
			$cockney = explode( ',', _x( "'tain't,'twere,'twas,'tis,'twill,'til,'bout,'nuff,'round,'cause,'em",
				'Comma-separated list of words to texturize in your language' ) );

			$cockneyreplace = explode( ',', _x( '&#8217;tain&#8217;t,&#8217;twere,&#8217;twas,&#8217;tis,&#8217;twill,&#8217;til,&#8217;bout,&#8217;nuff,&#8217;round,&#8217;cause,&#8217;em',
				'Comma-separated list of replacement words in your language' ) );
		}

		$static_characters = array_merge( array( '...', '``', '\'\'', ' (tm)' ), $cockney );
		$static_replacements = array_merge( array( '&#8230;', $opening_quote, $closing_quote, ' &#8482;' ), $cockneyreplace );


		// Pattern-based replacements of characters.
		// Sort the remaining patterns into several arrays for performance tuning.
		$dynamic_characters = array( 'apos' => array(), 'quote' => array(), 'dash' => array() );
		$dynamic_replacements = array( 'apos' => array(), 'quote' => array(), 'dash' => array() );
		$dynamic = array();
		$spaces = wp_spaces_regexp();

		// Embedded quotes "'Hello' he said" she said.
		if ( "'" !== $opening_single_quote || "'" !== $closing_single_quote || '"' !== $opening_quote || '"' !== $closing_quote ) {
			$dynamic[ '/(?<=\A|[([{\-]|&lt;|' . $spaces . ')\'"/' ] = $open_sq_flag . $open_q_flag;
			$dynamic[ '/(?<=\A|[([{\-]|&lt;|' . $spaces . ')"\'/' ] = $open_q_flag . $open_sq_flag;
			$dynamic[ '/\'"(?=\z|[.,:;!?)}\-\]]|&gt;|' . $spaces . ')/' ] = $close_sq_flag . $close_q_flag;
			$dynamic[ '/"\'(?=\z|[.,:;!?)}\-\]]|&gt;|' . $spaces . ')/' ] = $close_q_flag . $close_sq_flag;

			$dynamic_characters_embed = array_keys( $dynamic );
			$dynamic_replacements_embed = array_values( $dynamic );
			$dynamic = array();
		}

		// '99' and '99" are ambiguous among other patterns; assume it's an abbreviated year at the end of a quotation.
		if ( "'" !== $apos || "'" !== $closing_single_quote ) {
			$dynamic[ '/\'(\d\d)\'(?=\Z|[.,:;!?)}\-\]]|&gt;|' . $spaces . ')/' ] = $apos_flag . '$1' . $close_sq_flag;
		}
		if ( "'" !== $apos || '"' !== $closing_quote ) {
			$dynamic[ '/\'(\d\d)"(?=\Z|[.,:;!?)}\-\]]|&gt;|' . $spaces . ')/' ] = $apos_flag . '$1' . $close_q_flag;
		}

		// '99 '99s '99's (apostrophe)  But never '9 or '99% or '999 or '99.0.
		if ( "'" !== $apos ) {
			$dynamic[ '/\'(?=\d\d(?:\Z|(?![%\d]|[.,]\d)))/' ] = $apos_flag;
		}

		// Quoted Numbers like '0.42'
		if ( "'" !== $opening_single_quote || "'" !== $closing_single_quote ) {
			$dynamic[ '/(?<=\A|' . $spaces . ')\'(\d[.,\d]*)\'/' ] = $open_sq_flag . '$1' . $close_sq_flag;
		}

		// Single quote at start, or preceded by (, {, <, [, ", -, or spaces.
		if ( "'" !== $opening_single_quote ) {
			$dynamic[ '/(?<=\A|[([{"\-]|&lt;|' . $spaces . ')\'/' ] = $open_sq_flag;
		}

		// Apostrophe in a word.  No spaces, double apostrophes, or other punctuation.
		if ( "'" !== $apos ) {
			$dynamic[ '/(?<!' . $spaces . ')\'(?!\Z|[.,:;!?"\'(){}[\]\-]|&[lg]t;|' . $spaces . ')/' ] = $apos_flag;
		}

		$dynamic_characters['apos'] = array_keys( $dynamic );
		$dynamic_replacements['apos'] = array_values( $dynamic );
		$dynamic = array();

		// Quoted Numbers like "42"
		if ( '"' !== $opening_quote || '"' !== $closing_quote ) {
			$dynamic[ '/(?<=\A|' . $spaces . ')"(\d[.,\d]*)"/' ] = $open_q_flag . '$1' . $close_q_flag;
		}

		// Double quote at start, or preceded by (, {, <, [, -, or spaces, and not followed by spaces.
		if ( '"' !== $opening_quote ) {
			$dynamic[ '/(?<=\A|[([{\-]|&lt;|' . $spaces . ')"(?!' . $spaces . ')/' ] = $open_q_flag;
		}

		$dynamic_characters['quote'] = array_keys( $dynamic );
		$dynamic_replacements['quote'] = array_values( $dynamic );
		$dynamic = array();

		// Dashes and spaces
		$dynamic[ '/---/' ] = $em_dash;
		$dynamic[ '/(?<=^|' . $spaces . ')--(?=$|' . $spaces . ')/' ] = $em_dash;
		$dynamic[ '/(?<!xn)--/' ] = $en_dash;
		$dynamic[ '/(?<=^|' . $spaces . ')-(?=$|' . $spaces . ')/' ] = $en_dash;

		$dynamic_characters['dash'] = array_keys( $dynamic );
		$dynamic_replacements['dash'] = array_values( $dynamic );
	}

	// Must do this every time in case plugins use these filters in a context sensitive manner
	/**
	 * Filters the list of HTML elements not to texturize.
	 *
	 * @since 2.8.0
	 *
	 * @param array $default_no_texturize_tags An array of HTML element names.
	 */
	$no_texturize_tags = apply_filters( 'no_texturize_tags', $default_no_texturize_tags );
	/**
	 * Filters the list of shortcodes not to texturize.
	 *
	 * @since 2.8.0
	 *
	 * @param array $default_no_texturize_shortcodes An array of shortcode names.
	 */
	$no_texturize_shortcodes = apply_filters( 'no_texturize_shortcodes', $default_no_texturize_shortcodes );

	$no_texturize_tags_stack = array();
	$no_texturize_shortcodes_stack = array();

	preg_match_all( '@\[/?([^<>&/\[\]\x00-\x20=]++)@', $text, $matches );
	$tagnames = array_intersect( array_keys( $shortcode_tags ), $matches[1] );

	if ( $tagnames ) {
		// Set up shortcodes regular expression (used to strip within each split text part).
		$shortcode_regex = '|' . _get_wptexturize_shortcode_regex( $tagnames );

		// Set up no texturize shortcodes regular expression (used to split text input).
		// No texturize shortcodes must also be registered to be ignored, so intersect with tagnames array.
		$no_texturize_shortcodes = array_intersect( $no_texturize_shortcodes, $tagnames );
		$no_texturize_shortcode_regex = $no_texturize_shortcodes ? _get_wptexturize_shortcode_regex( $no_texturize_shortcodes ) : '';
	} else {
		$shortcode_regex = $no_texturize_shortcode_regex = '';
	}

	// Look for comments, non-inline (non-split) HTML elements and no texturize shortcodes.

	$regex = _pap_get_wptexturize_split_regex( $no_texturize_shortcode_regex );

	$textarr = preg_split( $regex, $text, -1, PREG_SPLIT_DELIM_CAPTURE );

	foreach ( $textarr as $curl_idx => &$curl ) {
		if ( 1 === $curl_idx % 2 ) {
			// Delimiter.
			$first = $curl[0];
			if ( '<' === $first ) {
				// If not a comment.
				if ( '<!--' !== substr( $curl, 0, 4 ) ) {
					// This is an HTML element delimiter.

					// Replace each & with &#038; unless it already looks like an entity.
					$curl = preg_replace( '/&(?!#(?:\d+|x[a-f0-9]+);|[a-z1-4]{1,8};)/i', '&#038;', $curl );

					_pap_wptexturize_pushpop_element( $curl, $no_texturize_tags_stack, $no_texturize_tags );
				}
			} elseif ( '[' === $first ) {
				// This is a shortcode delimiter.

				if ( '[[' !== substr( $curl, 0, 2 ) && ']]' !== substr( $curl, -2 ) ) {
					// Looks like a normal shortcode.
					_pap_wptexturize_pushpop_element( $curl, $no_texturize_shortcodes_stack, $no_texturize_shortcodes );
				} else {
					// Looks like an escaped shortcode.
				}
			}
		} elseif ( empty( $no_texturize_shortcodes_stack ) && empty( $no_texturize_tags_stack ) && '' !== trim( $curl ) ) {
			// This is neither a delimiter, nor is this content inside of no_texturize pairs.  Do texturize.

			// Add a space to any <br>s so that when stripped will be recognized as whitespace.
			if ( $have_br = ( false !== stripos( $curl, '<br' ) ) ) {
				$curl = preg_replace( '/<br[^>]*>/i', '$0 ', $curl );
			}

			if ( pap_wptexturize_replace_init( $curl, '/<[^>]*>' . $shortcode_regex . '/S' ) ) { // The study option here makes a big difference.

				pap_wptexturize_replace_str( $curl, $static_characters, $static_replacements );

				$have_q = false !== strpos( $curl, '"' ); // Need to check for double quotes beforehand in case they're turned into flags by $dynamic_characters_embed.
				if ( false !== strpos( $curl, "'" ) ) {
					if ( $dynamic_characters_embed && $have_q && ( false !== strpos( $curl, '\'"' ) || false !== strpos( $curl, '"\'' ) ) ) {
						pap_wptexturize_replace_regex( $curl, $dynamic_characters_embed, $dynamic_replacements_embed );
					}
					pap_wptexturize_replace_regex( $curl, $dynamic_characters['apos'], $dynamic_replacements['apos'] );
					pap_wptexturize_replace_str( $curl, "'", $sq_flag ); // Substitute single quotes with same-sized dummy so that pap_wptexturize_primes() doesn't alter size of string.
					$curl = pap_wptexturize_primes( $curl, $sq_flag, $prime_sq_flag, $open_sq_flag, $close_sq_flag, $primes_flag, $spaces );
					pap_wptexturize_replace_str( $curl, $flags_sq, $reals_sq ); // Reinstate real values.
				}
				if ( $have_q ) {
					pap_wptexturize_replace_regex( $curl, $dynamic_characters['quote'], $dynamic_replacements['quote'] );
					pap_wptexturize_replace_str( $curl, '"', $q_flag ); // Substitute double quotes with same-sized dummy so that pap_wptexturize_primes() doesn't alter size of string.
					$curl = pap_wptexturize_primes( $curl, $q_flag, $prime_q_flag, $open_q_flag, $close_q_flag, $primes_flag, $spaces );
					pap_wptexturize_replace_str( $curl, $flags_q, $reals_q ); // Reinstate real values.
				}
				if ( false !== strpos( $curl, '-' ) ) {
					pap_wptexturize_replace_regex( $curl, $dynamic_characters['dash'], $dynamic_replacements['dash'] );
				}

				// 9x9 (times), but never 0x9999
				if ( 1 === preg_match( '/(?<=\d)x\d/', $curl ) ) {
					// Searching for a digit is 10 times more expensive than for the x, so we avoid doing this one!
					pap_wptexturize_replace_regex( $curl, '/\b(\d(?(?<=0)[\d\.,]+|[\d\.,]*))x(?=\d[\d\.,]*\b)/', '$1&#215;' ); // Changed to use look ahead as can only deal with a single sub-replacement.
				}

				pap_wptexturize_replace_final( $curl );

			} else {

				$curl = str_replace( $static_characters, $static_replacements, $curl );

				$have_q = false !== strpos( $curl, '"' ); // Need to check for double quotes beforehand in case they're turned into flags by $dynamic_characters_embed.
				if ( false !== strpos( $curl, "'" ) ) {
					if ( $dynamic_characters_embed && $have_q && ( false !== strpos( $curl, '\'"' ) || false !== strpos( $curl, '"\'' ) ) ) {
						$curl = preg_replace( $dynamic_characters_embed, $dynamic_replacements_embed, $curl );
					}
					$curl = preg_replace( $dynamic_characters['apos'], $dynamic_replacements['apos'], $curl );
					$curl = pap_wptexturize_primes( $curl, "'", $prime, $open_sq_flag, $close_sq_flag, $primes_flag, $spaces );
					$curl = str_replace( array( $apos_flag, $open_sq_flag, $close_sq_flag ), array( $apos, $opening_single_quote, $closing_single_quote ), $curl );
				}
				if ( $have_q ) {
					$curl = preg_replace( $dynamic_characters['quote'], $dynamic_replacements['quote'], $curl );
					$curl = pap_wptexturize_primes( $curl, '"', $double_prime, $open_q_flag, $close_q_flag, $primes_flag, $spaces );
					$curl = str_replace( array( $open_q_flag, $close_q_flag ), array( $opening_quote, $closing_quote ), $curl );
				}
				if ( false !== strpos( $curl, '-' ) ) {
					$curl = preg_replace( $dynamic_characters['dash'], $dynamic_replacements['dash'], $curl );
				}

				// 9x9 (times), but never 0x9999
				if ( 1 === preg_match( '/(?<=\d)x\d/', $curl ) ) {
					// Searching for a digit is 10 times more expensive than for the x, so we avoid doing this one!
					$curl = preg_replace( '/\b(\d(?(?<=0)[\d\.,]+|[\d\.,]*))x(\d[\d\.,]*)\b/', '$1&#215;$2', $curl );
				}
			}

			// Remove any spaces added to <br>s at the start.
			if ( $have_br ) {
				$curl = preg_replace( '/(<br[^>]*>) /i', '$1', $curl );
			}

			// Replace each & with &#038; unless it already looks like an entity.
			$curl = preg_replace( '/&(?!#(?:\d+|x[a-f0-9]+);|[a-z1-4]{1,8};)/i', '&#038;', $curl );
		}
	}
	return implode( '', $textarr );
}

/**
 * Implements a logic tree to determine whether or not "7'." represents seven feet,
 * then converts the special char into either a prime char or a closing quote char.
 *
 * @since 4.3.0
 *
 * @param string $haystack    The plain text to be searched.
 * @param string $needle      The character to search for such as ' or ".
 * @param string $prime       The prime char to use for replacement.
 * @param string $open_quote  The opening quote char. Opening quote replacement must be
 *                            accomplished already.
 * @param string $close_quote The closing quote char to use for replacement.
 * @return string The $haystack value after primes and quotes replacements.
 */
function pap_wptexturize_primes( $haystack, $needle, $prime, $open_quote, $close_quote, $flag, $spaces ) {
	$flag_len = strlen( $flag );
	$quote_pattern = "/$needle(?=\\Z|[.,:;!?)}\\-\\]]|&gt;|" . $spaces . ")/";
	$prime_pattern    = "/(?<=\\d)$needle/";
	$flag_after_digit = "/(?<=\\d)$flag/";
	$flag_no_digit    = "/(?<!\\d)$flag/";

	$sentences = explode( $open_quote, $haystack );

	foreach ( $sentences as $key => &$sentence ) {
		if ( false === strpos( $sentence, $needle ) ) {
			continue;
		} elseif ( 0 !== $key && 0 === substr_count( $sentence, $close_quote ) ) {
			$sentence = preg_replace( $quote_pattern, $flag, $sentence, -1, $count );
			if ( $count > 1 ) {
				// This sentence appears to have multiple closing quotes.  Attempt Vulcan logic.
				$sentence = preg_replace( $flag_no_digit, $close_quote, $sentence, -1, $count2 );
				if ( 0 === $count2 ) {
					// Try looking for a quote followed by a period.
					$count2 = substr_count( $sentence, "$flag." );
					if ( $count2 > 0 ) {
						// Assume the rightmost quote-period match is the end of quotation.
						$pos = strrpos( $sentence, "$flag." );
					} else {
						// When all else fails, make the rightmost candidate a closing quote.
						// This is most likely to be problematic in the context of bug #18549.
						$pos = strrpos( $sentence, $flag );
					}
					$sentence = substr_replace( $sentence, $close_quote, $pos, $flag_len );
				}
				// Use conventional replacement on any remaining primes and quotes.
				$sentence = preg_replace( array( $prime_pattern, $flag_after_digit ), $prime, $sentence );
				$sentence = str_replace( $flag, $close_quote, $sentence );
			} elseif ( 1 === $count ) {
				// Found only one closing quote candidate, so give it priority over primes.
				$sentence = str_replace( $flag, $close_quote, $sentence );
				$sentence = preg_replace( $prime_pattern, $prime, $sentence );
			} else {
				// No closing quotes found.  Just run primes pattern.
				$sentence = preg_replace( $prime_pattern, $prime, $sentence );
			}
		} else {
			$sentence = preg_replace( array( $prime_pattern, $quote_pattern ), array( $prime, $close_quote ), $sentence );
		}
		if ( '"' === $needle[0] && false !== strpos( $sentence, $needle ) ) {
			$sentence = str_replace( $needle, $close_quote, $sentence );
		}
	}

	return implode( $open_quote, $sentences );
}

/**
 * Search for disabled element tags. Push element to stack on tag open and pop
 * on tag close.
 *
 * Assumes first char of $text is tag opening and last char is tag closing.
 * Assumes second char of $text is optionally '/' to indicate closing as in </html>.
 *
 * @since 2.9.0
 * @access private
 *
 * @param string $text Text to check. Must be a tag like `<html>` or `[shortcode]`.
 * @param array  $stack List of open tag elements.
 * @param array  $disabled_elements The tag names to match against. Spaces are not allowed in tag names.
 */
function _pap_wptexturize_pushpop_element( $text, &$stack, $disabled_elements ) {
	// Is it an opening tag or closing tag?
	if ( isset( $text[1] ) && '/' !== $text[1] ) {
		$space = strpos( $text, ' ' );
		if ( false === $space ) {
			$tag = substr( $text, 1, -1 );
		} else {
			$tag = substr( $text, 1, $space - 1 );
		}
		if ( in_array( $tag, $disabled_elements ) ) { // If $disabled_elements was array_flipped then could use hash lookup isset( $disabled_elements[ $tag ] ) here instead of linear lookup.
			/*
			 * This disables texturize until we find a closing tag of our type
			 * (e.g. <pre>) even if there was invalid nesting before that
			 *
			 * Example: in the case <pre>sadsadasd</code>"baba"</pre>
			 *          "baba" won't be texturize
			 */
			$stack[] = $tag;
		}
	} elseif ( $stack ) {
		$space = strpos( $text, ' ' );
		if ( false === $space ) {
			$tag = substr( $text, 2, -1 );
		} else {
			$tag = substr( $text, 2, $space - 2 );
		}
		if ( in_array( $tag, $disabled_elements ) && end( $stack ) === $tag ) { // Sim. could use isset( $disabled_elements[ $tag ] ) if above.
			array_pop( $stack );
		}
	}
}

/**
 * Initialize the stripped string routines pap_wptexturize_replace_XXX, setting the globals used.
 * $str will be stripped of any strings that match the regular expression $search.
 */
function pap_wptexturize_replace_init( &$str, $search ) {
	global $pap_wptexturize_strip_cnt, $pap_wptexturize_strips, $pap_wptexturize_adjusts;

	$pap_wptexturize_strip_cnt = 0;

	if ( preg_match_all( $search, $str, $matches, PREG_OFFSET_CAPTURE ) ) {
		$pap_wptexturize_strips = $pap_wptexturize_adjusts = $strs = array();
		$diff = 0;
		foreach ( $matches[0] as $entry ) {
			list( $match, $offset ) = $entry;
			$len = strlen( $match );
			// Save details of stripped string.
			$pap_wptexturize_strips[] = array( $match, $offset - $diff /*, $len /* Store len if not using byte array in pap_wptexturize_replace_final(). */ );
			$diff += $len;
			$strs[] = $match; // If using str_replace rather than (safer) preg_replace.
		}
		if ( $pap_wptexturize_strip_cnt = count( $pap_wptexturize_strips ) ) {
			$str = str_replace( $strs, '', $str ); // Assuming simple matches replaceable in whole string (otherwise need to do preg_replace( $search, '', $str )).
		}
	}
	return $pap_wptexturize_strip_cnt;
}

/**
 * Do a straight (non-regexp) string substitution, keeping tabs on the offset adjustments if have a stripped string.
 */
function pap_wptexturize_replace_str( &$str, $search, $repl ) {
	global $pap_wptexturize_strip_cnt, $pap_wptexturize_adjusts;

	if ( $pap_wptexturize_strip_cnt ) {
		// Process simple string search, given replacement string $repl.
		$searches = is_array( $search ) ? $search : array( $search );
		$repls = is_array( $repl ) ? $repl : array( $repl );

		// As replacements could interfere with later ones, treat each separately.
		foreach ( $searches as $idx => $search_str ) {
			if ( false !== ( $offset = strpos( $str, $search_str ) ) ) {
				$repl_str = $repls[$idx];
				$repl_len = strlen( $repl_str );
				$len = strlen( $search_str );
				$diff_len = $repl_len - $len;
				if ( $diff_len ) {
					$diff = 0;
					do {
						// Store adjustment details.
						$pap_wptexturize_adjusts[] = array( $offset + $diff, $repl_len, $len );
						$diff += $diff_len;
					} while ( false !== ( $offset = strpos( $str, $search_str, $offset + $len ) ) );
				}
				$str = str_replace( $search_str, $repl_str, $str );
			}
		}
	} else {
		$str = str_replace( $search, $repl, $str );
	}
}

/**
 * Do a regexp string substitution, keeping tabs on the offset adjustments if have a stripped string.
 */
function pap_wptexturize_replace_regex( &$str, $search, $repl ) {
	global $pap_wptexturize_strip_cnt, $pap_wptexturize_adjusts;

	if ( $pap_wptexturize_strip_cnt ) {
		// Process regex, given replacement string $repl.
		$searches = is_array( $search ) ? $search : array( $search );
		$repls = is_array( $repl ) ? $repl : array( $repl );

		// As replacements could interfere with later ones, treat each separately.
		foreach ( $searches as $idx => $re ) {
			if ( preg_match_all( $re, $str, $matches, PREG_OFFSET_CAPTURE ) ) {
				$repl_str = $repls[$idx];
				$repl_len = strlen( $repl_str );
				$diff = 0;
				// Allow for a single captured replacement.
				if ( false !== ( $pos1 = strpos( $repl_str, '$1' ) ) ) {
					foreach ( $matches[0] as $i => $entry ) {
						list( $match, $offset ) = $entry;
						// For a 'pre$1post' replacement, need to track pre-submatch replace and then post-submatch replace.
						$pre_repl_len = $pos1;
						$pre_len = $matches[1][$i][1] - $offset; // Submatch offset less full match offset.
						if ( $pre_repl_len !== $pre_len ) {
							// Store adjustment details.
							$pap_wptexturize_adjusts[] = array( $offset + $diff, $pre_repl_len, $pre_len );
							$diff += $pre_repl_len - $pre_len;
						}
						$len1 = strlen( $matches[1][$i][0] ); // Length of submatch string.
						$post_repl_len = $repl_len - ( $pre_repl_len + 2 );
						$post_len = strlen( $match ) - ( $pre_len + $len1 );
						if ( $post_repl_len !== $post_len ) {
							// Store adjustment details.
							$offset += $pre_len + $len1; // Jump over substituted pre-string & submatch.
							$pap_wptexturize_adjusts[] = array( $offset + $diff, $post_repl_len, $post_len );
							$diff += $post_repl_len - $post_len;
						}
					}
				} else {
					foreach ( $matches[0] as $entry ) {
						list( $match, $offset ) = $entry;
						$len = strlen( $match );
						if ( $repl_len !== $len ) {
							// Store adjustment details.
							$pap_wptexturize_adjusts[] = array( $offset + $diff, $repl_len, $len );
							$diff += $repl_len - $len;
						}
					}
				}
				$str = preg_replace( $re, $repl_str, $str );
			}
		}
	} else {
		$str = preg_replace( $search, $repl, $str );
	}
}

/**
 * Restore stripped strings to $str.
 */
function pap_wptexturize_replace_final( &$str ) {
	global $pap_wptexturize_strip_cnt, $pap_wptexturize_strips, $pap_wptexturize_adjusts;

	// Finalize - restore stripped strings.
	if ( $pap_wptexturize_strip_cnt ) {
		// Calculate offset adjustments.
		foreach ( $pap_wptexturize_adjusts as $entry ) {
			list( $offset, $repl_len, $len ) = $entry;
			for ( $i = $pap_wptexturize_strip_cnt - 1; $i >= 0 && $offset < ( $strip_offset = &$pap_wptexturize_strips[$i][1]); $i-- ) {
				if ( $len > 1 && $offset + 1 < $strip_offset ) {
					$strip_offset += $repl_len - $len;
				} else {
					$strip_offset += $repl_len - 1;
				}
			}
		}

		// Restore stripped strings.
		$str_arr = str_split( $str ); // Using byte array (seems to be a bit quicker than substr_replace()).
		array_unshift( $str_arr, '' );
		foreach ( $pap_wptexturize_strips as $entry ) {
			list( $strip, $offset ) = $entry;
			$str_arr[$offset] .= $strip;
		}
		$str = implode( '', $str_arr );
		unset( $str_arr );
		/* If not using byte array. (Note need to store $len in pap_wptexturize_replace_init()).
		$diff = 0;
		foreach ( $pap_wptexturize_strips as $entry ) {
			list( $strip, $offset, $len ) = $entry;
			$str = substr_replace( $str, $strip, $offset + $diff, 0 );
			$diff += $len;
		}
		/**/
		$pap_wptexturize_strip_cnt = 0;
	}
}

/**
 * Retrieve the combined regular expression for HTML and shortcodes.
 *
 * @access private
 * @ignore
 * @internal This function will be removed in 4.5.0 per Shortcode API Roadmap.
 * @since 4.4.0
 *
 * @param string $shortcode_regex The result from _get_wptexturize_shortcode_regex().  Optional.
 * @return string The regular expression
 */
function _pap_get_wptexturize_split_regex( $shortcode_regex = '' ) {
	static $html_regex;

	if ( ! isset( $html_regex ) ) {
		$comment_regex =
			  '!'           // Start of comment, after the <.
			. '(?:'         // Unroll the loop: Consume everything until --> is found.
			.     '-(?!->)' // Dash not followed by end of comment.
			.     '[^\-]*+' // Consume non-dashes.
			. ')*+'         // Loop possessively.
			. '(?:-->)?';   // End of comment. If not found, match all input.

		$nonsplit_regex = '\/?(?:a|abbr|b|big|br|cite|dfn|em|i|mark|q|s|samp|small|span|strong|sub|sup|u|var)(?![0-9A-Za-z])[^>]*>';

		$html_regex =			 // Needs replaced with wp_html_split() per Shortcode API Roadmap.
			  '<'                // Find start of element.
			. '(?(?=!--)'        // Is this a comment?
			.     $comment_regex // Find end of comment.
			. '|'
			.     '(?!' . $nonsplit_regex . ')' // Exclude inline html elements.
			.     '[^>]*>?'      // Find end of element. If not found, match all input.
			. ')';
	}

	if ( empty( $shortcode_regex ) ) {
		$regex = '/(' . $html_regex . ')/';
	} else {
		$regex = '/(' . $html_regex . '|' . $shortcode_regex . ')/';
	}

	return $regex;
}

