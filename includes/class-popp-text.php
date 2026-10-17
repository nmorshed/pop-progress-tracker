<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cleans user-entered plain text.
 *
 * Saved text is only ever rendered by React, which escapes it, so it is stored
 * exactly as typed. sanitize_text_field() is not used because it HTML-encodes
 * "<" and "&" and deletes "%XX" sequences, corrupting ordinary sales notes such
 * as "Deals < $5k". Anything that later prints these values from PHP must escape
 * them (esc_html / esc_attr).
 */
class POPP_Text {
	/** Single-line text: whitespace runs (including line breaks) collapse to one space. */
	public static function line( $value, int $max_length = 0 ): string {
		$text = self::clean( $value );
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return self::limit( $text, $max_length );
	}

	/** Multi-line text: line breaks and tabs are kept. */
	public static function block( $value, int $max_length = 0 ): string {
		$text = self::clean( $value );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		return self::limit( trim( $text ), $max_length );
	}

	private static function clean( $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$text = wp_check_invalid_utf8( (string) $value, true );
		// Drop control characters other than tab and line breaks.
		return (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text );
	}

	private static function limit( string $text, int $max_length ): string {
		if ( $max_length <= 0 ) {
			return $text;
		}
		$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max_length, 'UTF-8' ) : substr( $text, 0, $max_length );
		return rtrim( $text );
	}

	/**
	 * Reverses the HTML encoding that sanitize_text_field() applied to text saved
	 * before 4.1.1, e.g. "Deals &lt; $5k" back to "Deals < $5k".
	 */
	public static function decode_legacy( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'decode_legacy' ), $value );
		}
		if ( ! is_string( $value ) || false === strpos( $value, '&' ) ) {
			return $value;
		}
		return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}
}
