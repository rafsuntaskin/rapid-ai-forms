<?php
/**
 * Anti-abuse guard for the public submission endpoint.
 *
 * Layers, applied in cost order by Rest_Controller::submit():
 *   1. Origin/Referer allowlist (cheap header check).
 *   2. Per-IP rate limit (transient counter, hashed IP).
 *   3. Signed, lazily-issued submission token (HMAC, short TTL, uuid-bound).
 *   4. Honeypot + time-trap → silently accepted and discarded.
 *
 * The token is issued by a never-cached endpoint (GET /form-token/{uuid}) so
 * the rendered form HTML stays full-page-cache safe — nothing per-request is
 * baked into it. No client token can *prove* a human submitted; this raises
 * the cost of naive direct-POST / replay bots. CAPTCHA/Akismet stays a
 * pluggable hook (rapid_ai_forms_submission_pre_store).
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Forms;

defined( 'ABSPATH' ) || exit;

class Submission_Guard {

	const TOKEN_TTL        = 3600; // 1 hour — generous so long forms don't expire mid-fill; the token is fetched at page load.
	const MIN_FILL_SECONDS = 2;   // Reject submits faster than this after token issue.
	const RATE_LIMIT       = 20;  // Submissions per window per IP.
	const RATE_WINDOW      = 60;  // Seconds.
	const HONEYPOT_FIELD   = 'raif_hp';
	const SECRET_OPTION    = 'rapid_ai_forms_submit_secret';

	/**
	 * Signing secret, generated lazily on first use (autoload off; rotatable
	 * by deleting the option). Self-heals on existing installs.
	 */
	public static function secret() {
		$secret = get_option( self::SECRET_OPTION );
		if ( ! is_string( $secret ) || '' === $secret ) {
			$secret = wp_generate_password( 64, false );
			add_option( self::SECRET_OPTION, $secret, '', 'no' );
		}
		return $secret;
	}

	/**
	 * Mint a token bound to a form uuid: base64( ts . '.' . HMAC(uuid|ts) ).
	 *
	 * @param string $uuid Form uuid.
	 * @return array{token:string,ts:int}
	 */
	public static function mint( $uuid ) {
		$ts  = time();
		$sig = hash_hmac( 'sha256', $uuid . '|' . $ts, self::secret() );
		return array(
			'token' => base64_encode( $ts . '.' . $sig ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- opaque token, not obfuscation.
			'ts'    => $ts,
		);
	}

	/**
	 * Verify a token against a uuid. Returns the issue timestamp on success.
	 *
	 * @param string $uuid  Form uuid.
	 * @param string $token Token from the client.
	 * @return int|\WP_Error Issue timestamp, or a 403 WP_Error.
	 */
	public static function verify_token( $uuid, $token ) {
		$bad = new \WP_Error( 'raif_bad_token', __( 'Could not verify this submission. Please reload and try again.', 'rapid-ai-forms' ), array( 'status' => 403 ) );

		$decoded = base64_decode( (string) $token, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- opaque token.
		if ( ! is_string( $decoded ) || false === strpos( $decoded, '.' ) ) {
			return $bad;
		}
		list( $ts, $sig ) = explode( '.', $decoded, 2 );
		$ts               = (int) $ts;

		$expected = hash_hmac( 'sha256', $uuid . '|' . $ts, self::secret() );
		if ( ! hash_equals( $expected, (string) $sig ) ) {
			return $bad;
		}
		if ( $ts <= 0 || ( time() - $ts ) > self::TOKEN_TTL ) {
			return new \WP_Error( 'raif_expired_token', __( 'Your form session expired. Please reload and try again.', 'rapid-ai-forms' ), array( 'status' => 403 ) );
		}
		return $ts;
	}

	/** Pull the token from the request body or header. */
	public static function token_from_request( $req ) {
		$token = $req->get_param( '_raif_token' );
		if ( ! is_string( $token ) || '' === $token ) {
			$token = $req->get_header( 'x_raif_token' );
		}
		return is_string( $token ) ? $token : '';
	}

	/**
	 * Reject cross-origin submits when a browser sent Origin/Referer. Absent
	 * headers (non-browser clients) pass — the token layer still applies.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_origin( $req ) {
		$source = $req->get_header( 'origin' );
		if ( ! $source ) {
			$source = $req->get_header( 'referer' );
		}
		if ( ! $source ) {
			return true;
		}
		$host = wp_parse_url( $source, PHP_URL_HOST );
		$site = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host && $site && strtolower( $host ) !== strtolower( $site ) ) {
			return new \WP_Error( 'raif_bad_origin', __( 'Submission blocked.', 'rapid-ai-forms' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Per-IP fixed-window rate limit. Threshold is filterable; 0 disables it.
	 *
	 * @return true|\WP_Error
	 */
	public static function check_rate_limit( $req ) {
		$limit = (int) apply_filters( 'rapid_ai_forms_submission_rate_limit', self::RATE_LIMIT );
		if ( $limit <= 0 ) {
			return true;
		}
		$key   = 'raif_rl_' . md5( self::client_ip( $req ) );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			$err = new \WP_Error( 'raif_rate_limited', __( 'Too many submissions. Please try again shortly.', 'rapid-ai-forms' ), array( 'status' => 429 ) );
			$err->add_data( array( 'retry_after' => self::RATE_WINDOW ) );
			return $err;
		}
		set_transient( $key, $count + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * Honeypot filled, or submitted implausibly fast after token issue.
	 * Tripping is silently accepted-and-discarded so bots aren't tipped off.
	 *
	 * @param array $raw_payload Unsanitized submission payload.
	 * @param int   $issued_ts   Token issue timestamp.
	 * @return bool
	 */
	public static function is_trap_tripped( array $raw_payload, $issued_ts ) {
		if ( ! empty( $raw_payload[ self::HONEYPOT_FIELD ] ) ) {
			return true;
		}
		$min = (int) apply_filters( 'rapid_ai_forms_min_fill_seconds', self::MIN_FILL_SECONDS );
		if ( $min > 0 && ( time() - (int) $issued_ts ) < $min ) {
			return true;
		}
		return false;
	}

	/** Best-effort client IP (filterable for proxy setups); only ever hashed. */
	public static function client_ip( $req = null ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return (string) apply_filters( 'rapid_ai_forms_client_ip', $ip, $req );
	}
}
