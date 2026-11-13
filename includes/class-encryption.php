<?php
/**
 * API key / secret encryption.
 *
 * New values are encrypted with AES-256-GCM (authenticated encryption:
 * tampering is detected). Legacy AES-256-CBC values written by earlier
 * versions are still transparently decrypted, and are upgraded to GCM
 * the next time the owning option is re-saved.
 *
 * The key is derived from wp_salt( 'auth' ). If the site's salts are
 * rotated, previously stored values can no longer be decrypted — in
 * that case decrypt() fails safe (returns '' and logs) rather than
 * returning the still-encrypted blob, so a corrupt credential is never
 * sent to a third-party API. The affected settings field renders empty,
 * prompting the admin to re-enter the secret.
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AES-256-GCM encryption for stored API keys and OAuth secrets, with
 * transparent decryption of legacy AES-256-CBC values.
 */
class SWPS_Encryption {

	private const METHOD_GCM    = 'aes-256-gcm';
	private const METHOD_LEGACY = 'aes-256-cbc';

	/** Prefix on every encrypted value (legacy CBC and current GCM). */
	private const PREFIX = 'encrypted:';

	/** Prefix identifying the authenticated (GCM) format. */
	private const PREFIX_V2 = 'encrypted:v2:';

	private const GCM_IV_LEN  = 12;
	private const GCM_TAG_LEN = 16;

	/**
	 * Encrypt a value with AES-256-GCM.
	 *
	 * @param string $value Plain text value.
	 * @return string Encrypted "encrypted:v2:<base64(iv|tag|ciphertext)>",
	 *                or '' if encryption failed (never the plaintext).
	 */
	public static function encrypt( string $value ): string {
		if ( '' === $value || self::is_encrypted( $value ) ) {
			return $value;
		}

		try {
			$iv = random_bytes( self::GCM_IV_LEN );
		} catch ( \Exception $e ) {
			self::log( 'no CSPRNG available for IV generation' );
			return '';
		}

		$tag        = '';
		$ciphertext = openssl_encrypt(
			$value,
			self::METHOD_GCM,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag,
			'',
			self::GCM_TAG_LEN
		);

		if ( false === $ciphertext ) {
			// Do NOT fall back to storing the plaintext secret.
			self::log( 'openssl_encrypt failed' );
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of raw ciphertext, not obfuscation.
		return self::PREFIX_V2 . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Decrypt a value.
	 *
	 * Plain (unencrypted) input is returned unchanged — this supports
	 * installs that stored a secret before encryption existed. A value
	 * that IS encrypted but cannot be decrypted (rotated salts, tampered
	 * or truncated blob) returns '' so callers treat it as "no secret"
	 * instead of using a corrupt one.
	 *
	 * @param string $value Stored value.
	 * @return string Decrypted plain text, '' on failure, or the input
	 *                unchanged when it was never encrypted.
	 */
	public static function decrypt( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		if ( str_starts_with( $value, self::PREFIX_V2 ) ) {
			return self::decrypt_gcm( substr( $value, strlen( self::PREFIX_V2 ) ) );
		}

		if ( str_starts_with( $value, self::PREFIX ) ) {
			return self::decrypt_legacy_cbc( substr( $value, strlen( self::PREFIX ) ) );
		}

		// Not encrypted — pre-encryption stored value.
		return $value;
	}

	/**
	 * Decrypt an AES-256-GCM payload.
	 *
	 * @param string $payload base64( iv | tag | ciphertext ).
	 * @return string Plain text, or '' on failure.
	 */
	private static function decrypt_gcm( string $payload ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding our own ciphertext transport encoding.
		$raw = base64_decode( $payload, true );

		if ( false === $raw || strlen( $raw ) <= self::GCM_IV_LEN + self::GCM_TAG_LEN ) {
			self::log( 'GCM payload malformed' );
			return '';
		}

		$iv         = substr( $raw, 0, self::GCM_IV_LEN );
		$tag        = substr( $raw, self::GCM_IV_LEN, self::GCM_TAG_LEN );
		$ciphertext = substr( $raw, self::GCM_IV_LEN + self::GCM_TAG_LEN );

		$plaintext = openssl_decrypt(
			$ciphertext,
			self::METHOD_GCM,
			self::get_key(),
			OPENSSL_RAW_DATA,
			$iv,
			$tag
		);

		if ( false === $plaintext ) {
			self::log( 'GCM decrypt failed (rotated salts or tampered value)' );
			return '';
		}

		return $plaintext;
	}

	/**
	 * Decrypt a legacy AES-256-CBC payload written by older versions.
	 *
	 * @param string $payload "<base64(iv)>:<ciphertext>".
	 * @return string Plain text, or '' on failure.
	 */
	private static function decrypt_legacy_cbc( string $payload ): string {
		$parts = explode( ':', $payload, 2 );

		if ( count( $parts ) !== 2 ) {
			self::log( 'legacy payload malformed' );
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding the legacy IV transport encoding.
		$iv         = base64_decode( $parts[0], true );
		$ciphertext = $parts[1];

		if ( false === $iv ) {
			self::log( 'legacy IV malformed' );
			return '';
		}

		$plaintext = openssl_decrypt( $ciphertext, self::METHOD_LEGACY, self::get_key(), 0, $iv );

		if ( false === $plaintext ) {
			self::log( 'legacy CBC decrypt failed (rotated salts)' );
			return '';
		}

		return $plaintext;
	}

	/**
	 * Whether a value is in either encrypted format.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	public static function is_encrypted( string $value ): bool {
		return str_starts_with( $value, self::PREFIX );
	}

	/**
	 * Whether a value is encrypted in the legacy (non-authenticated)
	 * CBC format and should be re-encrypted on next save.
	 *
	 * @param string $value Value to check.
	 * @return bool
	 */
	public static function is_legacy_format( string $value ): bool {
		return str_starts_with( $value, self::PREFIX )
			&& ! str_starts_with( $value, self::PREFIX_V2 );
	}

	/**
	 * Derive the 32-byte key from the WordPress auth salt.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function get_key(): string {
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/**
	 * Log an encryption failure without ever logging secret material.
	 *
	 * @param string $reason Short, non-sensitive description.
	 * @return void
	 */
	private static function log( string $reason ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'SWPS_Encryption: ' . $reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- diagnostic for an unrecoverable crypto failure; no secret material logged.
		}
	}

	/**
	 * Option name flag set when plaintext secret migration has completed
	 * successfully (no remaining plaintext secrets, no encrypt failures).
	 */
	public const MIGRATION_COMPLETE_OPTION = 'swps_secret_options_migrated_v1';

	/**
	 * Secret option names (full option names) that must be encrypted at rest.
	 * Excludes swps_indexnow_api_key (public key by design).
	 *
	 * @return string[]
	 */
	public static function secret_option_names(): array {
		return array(
			'swps_api_key', // Legacy global key; preserved for legacy copy-to-Anthropic flow.
			'swps_anthropic_api_key',
			'swps_openai_api_key',
			'swps_google_api_key',
			'swps_xai_api_key',
			'swps_opencode_go_api_key',
			'swps_unsplash_api_key',
			'swps_pexels_api_key',
			'swps_pixabay_api_key',
			'swps_jon_ai_secret',
			'swps_gsc_client_secret',
		);
	}

	/**
	 * Whether a full option name is a secret stored encrypted at rest.
	 */
	public static function is_secret_option( string $option_name ): bool {
		return in_array( $option_name, static::secret_option_names(), true );
	}

	/**
	 * Get a secret option value, decrypting if needed.
	 *
	 * Plaintext legacy values are returned unchanged (migration-safe).
	 */
	public static function get_secret_option( string $option_name, string $default = '' ): string {
		$value = get_option( $option_name, $default );
		if ( ! is_string( $value ) || '' === $value ) {
			return is_string( $value ) ? $value : (string) $default;
		}
		return static::decrypt( $value );
	}

	/**
	 * Encrypt a secret option value for storage.
	 *
	 * @param string $option_name Full option name.
	 * @param mixed  $value       Submitted value.
	 * @return array{value:string, error:bool} Stored value (preserved existing on failure/empty) and error flag.
	 */
	public static function encrypt_option_for_storage( string $option_name, $value ): array {
		$existing = (string) get_option( $option_name, '' );
		if ( null === $value || '' === $value ) {
			return array( 'value' => $existing, 'error' => false );
		}
		$sanitized = sanitize_text_field( (string) $value );
		if ( '' === $sanitized ) {
			return array( 'value' => $existing, 'error' => false );
		}
		$encrypted = static::encrypt( $sanitized );
		if ( '' === $encrypted ) {
			return array( 'value' => $existing, 'error' => true );
		}
		return array( 'value' => $encrypted, 'error' => false );
	}

	/**
	 * Migrate plaintext secret options to encrypted-at-rest values.
	 *
	 * Idempotent and failure-safe: encrypts only non-empty non-encrypted values;
	 * leaves values untouched if encryption fails. Uses compare-and-swap so a
	 * concurrently saved/new value is never overwritten by migration.
	 *
	 * @return array{migrated:string[], failed:string[], skipped:string[], complete:bool}
	 */
	public static function migrate_secret_options(): array {
		$result = array( 'migrated' => array(), 'failed' => array(), 'skipped' => array(), 'complete' => false );
		foreach ( static::secret_option_names() as $option_name ) {
			$value = get_option( $option_name, '' );
			if ( ! is_string( $value ) || '' === $value || static::is_encrypted( $value ) ) {
				continue;
			}
			$encrypted = static::encrypt( $value );
			if ( '' === $encrypted ) {
				$result['failed'][] = $option_name;
				continue;
			}

			// Compare-and-swap: only update if option value still equals the value read.
			$updated = static::compare_and_swap_option( $option_name, $value, $encrypted );
			if ( $updated ) {
				$result['migrated'][] = $option_name;
			} else {
				// Changed concurrently (e.g. newly saved) or write failed; do not clobber.
				$result['skipped'][] = $option_name;
			}
		}

		// Completion flag: safe to skip future scans only when no plaintext remains
		// and no encrypt failures occurred (so failures can be retried).
		if ( empty( $result['failed'] ) && ! static::has_unencrypted_secret_options() ) {
			$result['complete'] = true;
			update_option( static::MIGRATION_COMPLETE_OPTION, 1, false );
		}

		return $result;
	}

	/**
	 * Production migration guard: run migration only when needed.
	 *
	 * Skips when completion flag is set AND no plaintext secrets remain, so it is
	 * safe and cheap on subsequent admin requests while still retryable after failure.
	 *
	 * @return array{migrated:string[], failed:string[], skipped:string[], complete:bool}|null
	 *         Migration result, or null if skipped by guard.
	 */
	public static function maybe_migrate_secret_options(): ?array {
		if ( get_option( static::MIGRATION_COMPLETE_OPTION ) && ! static::has_unencrypted_secret_options() ) {
			return null;
		}
		return static::migrate_secret_options();
	}

	/**
	 * Copy legacy swps_api_key into swps_anthropic_api_key when the target is empty.
	 *
	 * Preserves legacy option (never deletes/clears it) and only copies when the
	 * Anthropic key is empty. Reads decrypted legacy value if stored encrypted.
	 *
	 * @return array{copied:bool, legacy_present:bool, target_was_empty:bool}
	 */
	public static function migrate_legacy_api_key_to_anthropic(): array {
		$legacy_raw = get_option( 'swps_api_key', '' );
		$legacy_present = is_string( $legacy_raw ) && '' !== $legacy_raw;
		$legacy_value = $legacy_present ? static::decrypt( $legacy_raw ) : '';
		$target = get_option( 'swps_anthropic_api_key', '' );
		$target_empty = ! is_string( $target ) || '' === $target;

		$copied = false;
		if ( '' !== $legacy_value && $target_empty ) {
			update_option( 'swps_anthropic_api_key', $legacy_value );
			$copied = true;
		}

		return array(
			'copied'          => $copied,
			'legacy_present'  => $legacy_present,
			'target_was_empty'=> $target_empty,
		);
	}

	/**
	 * Whether any configured secret option still contains a non-empty plaintext value.
	 */
	public static function has_unencrypted_secret_options(): bool {
		foreach ( static::secret_option_names() as $option_name ) {
			$value = get_option( $option_name, '' );
			if ( is_string( $value ) && '' !== $value && ! static::is_encrypted( $value ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Update an option only if its stored value still matches $expected (compare-and-swap).
	 *
	 * Uses binary/exact option_value comparison so case-insensitive DB collation
	 * cannot treat a changed value as equal.
	 *
	 * @return bool True if the expected value was replaced.
	 */
	public static function compare_and_swap_option( string $option_name, string $expected, string $new_value ): bool {
		global $wpdb;
		if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) ) {
			$table = $wpdb->options;

			// Exact atomic CAS via binary comparison; no non-atomic DB fallback.
			if ( method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'query' ) ) {
				$sql = $wpdb->prepare(
					"UPDATE {$table} SET option_value = %s WHERE option_name = %s AND option_value = BINARY %s",
					$new_value,
					$option_name,
					$expected
				);
				if ( false === $sql ) {
					// Observable, secret-free diagnostic; retry on a later admin request.
					self::log( 'compare-and-swap prepare failed for option: ' . $option_name );
					return false;
				}

				$rows = $wpdb->query( $sql );
				if ( false !== $rows && 0 !== $rows && 0 !== (int) $rows ) {
					if ( function_exists( 'wp_cache_delete' ) ) {
						wp_cache_delete( $option_name, 'options' );
						wp_cache_delete( 'alloptions', 'options' );
						wp_cache_delete( 'notoptions', 'options' );
					}
					return true;
				}
				if ( false === $rows ) {
					// DB error: fail safely so migration can retry later; no non-atomic fallback.
					self::log( 'compare-and-swap query failed for option: ' . $option_name );
				}
				// 0 rows (concurrent change/no match) or DB error handled above.
				return false;
			}

			// DB present but exact atomic CAS unavailable: fail safely (no non-atomic DB fallback).
			self::log( 'compare-and-swap unavailable (missing prepare/query) for option: ' . $option_name );
			return false;
		}

		$current = get_option( $option_name, null );
		if ( ! is_string( $current ) || 0 !== strcmp( $current, $expected ) ) {
			return false;
		}
		return (bool) update_option( $option_name, $new_value );
	}

}
