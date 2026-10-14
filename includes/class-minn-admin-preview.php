<?php
/**
 * The preview policy: which tags a preview made from a real entry resolves.
 *
 * A notification preview, and a test send to the person previewing, fills a
 * vendor's tags from a stored entry for someone who may not be allowed to
 * read what a tag reaches: the account that submitted the entry, the page the
 * form was sent from, the request of another visitor, or whatever an add-on's
 * tag does when it runs (User Registration's {set_password_url} mints a
 * password reset for the account tied to the entry). Tags are code, and
 * add-ons register their own, so no list of the dangerous ones is complete.
 * A preview resolves only the tags its adapter names: the vendor's own tags
 * that read the entry being previewed, the form, the site or the person
 * previewing. Every other tag in the subject, the message and the addresses
 * becomes a visible placeholder before the vendor reads the text. A real send
 * never passes through here.
 *
 * Brace tags (WPForms, Fluent Forms, Gravity Forms) are read the way their
 * vendors' patterns read them: from an opening brace to the first closing
 * one, with nothing trimmed. A run of braces ({{tag}}, {a {b}}) is one tag
 * with a brace in its name, which no allowlist names, so the text the vendor
 * gets holds no opening brace outside an allowlisted tag, and nothing in it
 * can be read as, or joined into, a tag the allowlist leaves out. A closing
 * brace left over stays: the only vendor pattern that reads past a closing
 * brace is Gravity Forms' field pattern, which reads the entry. Bracket tags
 * (Contact Form 7) are matched with CF7's own pattern, widened to line
 * breaks because Minn's own fill reads a tag across one.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Preview {

	/**
	 * Contact Form 7's mail-tag pattern (includes/mail.php), \s for its [\t ].
	 */
	const CF7_TAG = '/\[\s*([a-zA-Z_][0-9a-zA-Z:._-]*)((?:\s+"[^"]*"|\s+\'[^\']*\')*)\s*\]/';

	/**
	 * Fluent Forms routes a tag to the first of these it finds anywhere in the
	 * tag, in this order, and reads the key from the marker's length counted
	 * from the start (ShortCodeParser::parse).
	 */
	const FLUENT_GROUPS = array( 'inputs.', 'labels.', 'user.', 'embed_post.', 'wp.', 'submission.', 'cookie.', 'payment.' );

	/**
	 * The text with every tag the allowlist does not name as a placeholder.
	 *
	 * @param string $text    A subject, message or address, as typed.
	 * @param string $grammar 'wpforms' ({name}, {name attr="…"}), 'fluent'
	 *                        ({group.key}, {inputs.x}), 'gf' ({Label:id:modifier},
	 *                        {name:…}) or 'cf7' ([name], [_special]).
	 * @param array  $allow   Tag names the vendor may resolve. A name ending in
	 *                        '*' admits every name it starts with. A Gravity
	 *                        Forms field tag is named by its input id (':3',
	 *                        ':3.2'), whatever its label and modifiers.
	 * @return string
	 */
	public static function restrict( $text, $grammar, array $allow ) {
		$text = (string) $text;
		if ( 'cf7' === $grammar ) {
			return (string) preg_replace_callback( self::CF7_TAG, function ( $m ) use ( $allow ) {
				return self::allows( $m[1], $allow ) ? $m[0] : self::placeholder( $m[0] );
			}, $text );
		}
		$out = '';
		$at  = 0;
		while ( false !== ( $open = strpos( $text, '{', $at ) ) ) {
			$close = strpos( $text, '}', $open );
			if ( false === $close ) {
				break; // no pattern reads a tag without a closing brace
			}
			$tag  = substr( $text, $open, $close - $open + 1 );
			$read = self::read( substr( $tag, 1, -1 ), $grammar );
			$out .= substr( $text, $at, $open - $at );
			$out .= $read && self::allows( $read[0], $allow ) ? $read[1] : self::placeholder( $tag );
			$at   = $close + 1;
		}
		return $out . substr( $text, $at );
	}

	/**
	 * A brace tag as its vendor reads it: [ name, the tag to hand over ], or
	 * null when the text between the braces is not a tag of that grammar
	 * (which no allowlist admits).
	 *
	 * @param string $inner   The text between the braces.
	 * @param string $grammar See restrict().
	 * @return array|null
	 */
	public static function read( $inner, $grammar ) {
		$inner = (string) $inner;
		if ( '' === $inner || false !== strpbrk( $inner, "{}\n\r" ) ) {
			return null;
		}
		$tag = '{' . $inner . '}';
		switch ( $grammar ) {
			case 'wpforms':
				// Their finder: the name, then nothing or a space or "=" and the
				// attributes (wpforms_get_all_smart_tags()).
				return preg_match( '/^([a-z0-9_]+)(?:[ =].*)?$/', $inner, $m ) ? array( $m[1], $tag ) : null;
			case 'fluent':
				// A tag that holds a group's marker anywhere but at its start is
				// routed to that group with its key cut short: not a tag here.
				foreach ( array_merge( self::FLUENT_GROUPS, array( 'random_string.', 'chat_gpt_response.' ) ) as $group ) {
					$p = strpos( $inner, $group );
					if ( false !== $p ) {
						return 0 === $p ? array( $inner, $tag ) : null;
					}
				}
				return preg_match( '/^[a-z0-9_]+(?:\..+)?$/', $inner ) ? array( $inner, $tag ) : null;
			case 'gf':
				// Their field pattern, the label up to the first ":<id>". The
				// label is ignored when the field is filled, and it is where
				// another family's name (entry, user, an add-on's data key)
				// would take the tag elsewhere, so the field tag goes over
				// without it.
				if ( preg_match( '/^[^{]*?:(\d+(?:\.\w+)?)((?::.*)?)$/', $inner, $m ) ) {
					return array( ':' . $m[1], '{:' . $m[1] . $m[2] . '}' );
				}
				// Gravity Forms drops one trailing space from a tag's key
				// ({created_by:display_name } reads display_name), so the
				// allowlist is asked without it.
				return preg_match( '/^[a-z0-9_]+(?::.+)?$/', $inner ) ? array( preg_replace( '/ $/', '', $inner ), $tag ) : null;
		}
		return null;
	}

	/**
	 * Whether the allowlist names a tag.
	 *
	 * @param string $name  The tag's name, as read().
	 * @param array  $allow See restrict().
	 * @return bool
	 */
	public static function allows( $name, array $allow ) {
		$name = (string) $name;
		foreach ( $allow as $a ) {
			$a = (string) $a;
			if ( $a === $name || ( '*' === substr( $a, -1 ) && '' !== $name && 0 === strpos( $name, substr( $a, 0, -1 ) ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What a tag the preview leaves unresolved shows as: "[tag: name]", the
	 * tag as typed less its delimiters and anything outside a plain set. A
	 * translated format in which a grammar would find a tag falls back to the
	 * English one, in which none does.
	 *
	 * @param string $tag The whole tag, delimiters included.
	 * @return string
	 */
	public static function placeholder( $tag ) {
		$name = trim( (string) preg_replace( '/\s+/', ' ', (string) preg_replace( '/[^A-Za-z0-9 _.:=\'"|\/-]/', ' ', (string) $tag ) ) );
		$name = strlen( $name ) > 60 ? substr( $name, 0, 60 ) . '...' : $name;
		/* translators: %s: a merge tag or smart tag the preview leaves unresolved, as typed. */
		$format = __( '[tag: %s]', 'minn-admin' );
		if ( false !== strpbrk( $format, '{}' ) || preg_match( self::CF7_TAG, sprintf( $format, 'x' ) ) ) {
			$format = '[tag: %s]';
		}
		return sprintf( $format, $name );
	}

	/**
	 * Runs $render with only the vendor's own callbacks on $hooks: those whose
	 * code lives under $dir. An add-on resolves its tags on the vendor's hooks
	 * that see the whole text, and the text a tag inserts (a field value the
	 * submitter typed) can carry an add-on's tag the allowlist never saw.
	 * Callbacks added while $render runs are dropped with the stand-in.
	 *
	 * @param string[] $hooks  Hook names.
	 * @param string   $dir    The vendor's plugin directory.
	 * @param callable $render Called with no arguments.
	 * @return mixed What $render returns.
	 */
	public static function own_callbacks( array $hooks, $dir, callable $render ) {
		global $wp_filter;
		$dir  = trailingslashit( wp_normalize_path( (string) $dir ) );
		$held = array();
		foreach ( $hooks as $hook ) {
			$held[ $hook ] = isset( $wp_filter[ $hook ] ) && $wp_filter[ $hook ] instanceof WP_Hook ? $wp_filter[ $hook ] : null;
			$only          = new WP_Hook();
			foreach ( $held[ $hook ] ? $held[ $hook ]->callbacks : array() as $priority => $callbacks ) {
				foreach ( $callbacks as $cb ) {
					if ( self::defined_in( $cb['function'], $dir ) ) {
						$only->add_filter( $hook, $cb['function'], $priority, $cb['accepted_args'] );
					}
				}
			}
			$wp_filter[ $hook ] = $only; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		}
		try {
			return $render();
		} finally {
			foreach ( $held as $hook => $was ) {
				if ( $was ) {
					$wp_filter[ $hook ] = $was; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
				} else {
					unset( $wp_filter[ $hook ] );
				}
			}
		}
	}

	/**
	 * Whether a callback's code lives under $dir. Anything that can't be
	 * traced does not.
	 *
	 * @param callable $fn  Callback.
	 * @param string   $dir A directory, with its trailing slash.
	 * @return bool
	 */
	public static function defined_in( $fn, $dir ) {
		try {
			if ( is_string( $fn ) && false !== strpos( $fn, '::' ) ) {
				$fn = explode( '::', $fn, 2 );
			}
			if ( is_array( $fn ) ) {
				$ref = new ReflectionMethod( $fn[0], $fn[1] );
			} elseif ( is_object( $fn ) && ! $fn instanceof Closure ) {
				$ref = new ReflectionMethod( $fn, '__invoke' );
			} else {
				$ref = new ReflectionFunction( $fn );
			}
		} catch ( Throwable $e ) {
			return false;
		}
		$file = $ref->getFileName();
		return is_string( $file ) && '' !== $dir && 0 === strpos( wp_normalize_path( $file ), $dir );
	}
}
