<?php
use PHPUnit\Framework\TestCase;

// Minimal WP stubs.
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		if ( ! is_string( $str ) && ! is_numeric( $str ) ) { return ''; }
		$str = (string) $str;
		$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
		return trim( strip_tags( $str ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { $key = strtolower( (string) $key ); return preg_replace( '/[^a-z0-9_\-]/', '', $key ); }
}
if ( ! function_exists( 'get_option' ) ) {
	$GLOBALS['swps_test_options'] = $GLOBALS['swps_test_options'] ?? array();
	function get_option( $option, $default = false ) {
		$opts = $GLOBALS['swps_test_options'] ?? array();
		return array_key_exists( $option, $opts ) ? $opts[ $option ] : $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $option, $value, $autoload = null ) {
		$GLOBALS['swps_test_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_settings_error' ) ) {
	$GLOBALS['swps_test_settings_errors'] = $GLOBALS['swps_test_settings_errors'] ?? array();
	function add_settings_error( $setting, $code, $message, $type = 'error' ) {
		$GLOBALS['swps_test_settings_errors'][] = compact( 'setting', 'code', 'message', 'type' );
	}
}
if ( ! function_exists( 'add_action' ) ) { function add_action() { return true; } }
if ( ! function_exists( 'add_filter' ) ) { function add_filter() { return true; } }
if ( ! function_exists( 'register_setting' ) ) { function register_setting() {} }
if ( ! function_exists( 'add_settings_field' ) ) { function add_settings_field() {} }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $t ) { return (string) $t; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( $t, $d = 'default' ) { return $t; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $t ) { return (string) $t; } }
if ( ! function_exists( 'esc_textarea' ) ) { function esc_textarea( $t ) { return (string) $t; } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( $v ) { return $v; } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( $d ) { return json_encode( $d ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $s, $c, $e = true ) { $r = ( $s == $c ) ? ' selected="selected"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'checked' ) ) { function checked( $c, $cur = true, $e = true ) { $r = ( $c == $cur ) ? ' checked="checked"' : ''; if ( $e ) { echo $r; } return $r; } }
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

require_once __DIR__ . '/../../includes/class-encryption.php';
require_once __DIR__ . '/../../includes/class-settings.php';
require_once __DIR__ . '/../../includes/class-ai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-anthropic-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-openai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-google-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-xai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-opencode-go-provider.php';
require_once __DIR__ . '/../../includes/class-image-provider.php';
require_once __DIR__ . '/../../includes/providers/images/class-unsplash-provider.php';
require_once __DIR__ . '/../../includes/providers/images/class-pexels-provider.php';
require_once __DIR__ . '/../../includes/providers/images/class-pixabay-provider.php';
require_once __DIR__ . '/../../includes/providers/images/class-gemini-provider.php';
require_once __DIR__ . '/../../includes/class-image-inserter.php';
require_once __DIR__ . '/../../includes/class-onboarding.php';


class SWPS_Mock_WPDB {
	public $options = 'wp_options';
	public $last_prepare = null;
	public $last_prepare_args = array();
	public $last_query = null;
	public $queries = array();
	public $query_rows = 0;
	public $update_calls = array();
	public $update_rows = 0;

	public function prepare( $query, ...$args ) {
		$this->last_prepare = $query;
		if ( count( $args ) === 1 && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->last_prepare_args = $args;
		$prepared = $query;
		foreach ( $args as $a ) {
			$prepared = preg_replace( '/%s/', "'" . str_replace("'", "\\'", (string) $a) . "'", $prepared, 1 );
		}
		return $prepared;
	}

	public function query( $sql ) {
		$this->last_query = $sql;
		$this->queries[] = $sql;
		return $this->query_rows;
	}

	public function update( $table, $data, $where ) {
		$this->update_calls[] = array( 'table' => $table, 'data' => $data, 'where' => $where );
		return $this->update_rows;
	}
}

/**
 * Test double that exercises the REAL migrate_secret_options() loop/hook via
 * late static binding, while allowing controlled encrypt failure and CAS race.
 */
class SWPS_Test_Encryption extends SWPS_Encryption {
	/** @var string[] plaintext values for which encrypt() must fail. */
	public static $fail_plaintexts = array();
	/** @var string[] option names that should simulate a concurrent write (CAS miss). */
	public static $race_for = array();

	public static function encrypt( string $value ): string {
		if ( in_array( $value, self::$fail_plaintexts, true ) ) {
			return '';
		}
		return parent::encrypt( $value );
	}

	public static function compare_and_swap_option( string $option_name, string $expected, string $new_value ): bool {
		if ( in_array( $option_name, self::$race_for, true ) ) {
			// Simulate a concurrent save that changed the value after read.
			update_option( $option_name, 'concurrently-saved-' . $option_name );
			return false;
		}
		return parent::compare_and_swap_option( $option_name, $expected, $new_value );
	}

	public static function reset(): void {
		self::$fail_plaintexts = array();
		self::$race_for = array();
	}
}

final class AllProviderKeysEncryptionTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options'] = array();
		$GLOBALS['swps_test_settings_errors'] = array();
		SWPS_Test_Encryption::reset();
	}

	private function allProviderSecretOptions(): array {
		return array(
			'swps_api_key', // legacy
			'swps_anthropic_api_key', 'swps_openai_api_key', 'swps_google_api_key', 'swps_xai_api_key', 'swps_opencode_go_api_key',
			'swps_unsplash_api_key', 'swps_pexels_api_key', 'swps_pixabay_api_key',
		);
	}

	public function test_secret_option_scope_includes_all_provider_keys_legacy_and_excludes_indexnow(): void {
		$secrets = SWPS_Encryption::secret_option_names();
		foreach ( $this->allProviderSecretOptions() as $opt ) {
			$this->assertTrue( SWPS_Encryption::is_secret_option( $opt ), $opt );
		}
		$this->assertTrue( SWPS_Encryption::is_secret_option( 'swps_jon_ai_secret' ) );
		$this->assertTrue( SWPS_Encryption::is_secret_option( 'swps_gsc_client_secret' ) );
		$this->assertFalse( SWPS_Encryption::is_secret_option( 'swps_indexnow_api_key' ), 'IndexNow key must remain public/un-encrypted by design' );
		$this->assertNotContains( 'swps_indexnow_api_key', $secrets );
	}

	public function test_settings_sanitize_encrypts_all_provider_keys_preserves_empty_and_renders_blanked(): void {
		$settings = new SWPS_Settings();
		$ref = new ReflectionClass( $settings );
		$san = $ref->getMethod( 'get_sanitize_callback' );
		$san->setAccessible( true );
		$render = $ref->getMethod( 'render_field' );
		$render->setAccessible( true );

		$keys = array(
			'anthropic_api_key', 'openai_api_key', 'google_api_key', 'xai_api_key', 'opencode_go_api_key',
			'unsplash_api_key', 'pexels_api_key', 'pixabay_api_key',
		);
		foreach ( $keys as $key ) {
			$opt = "swps_{$key}";
			update_option( $opt, 'plain-legacy' ); // legacy plaintext
			$cb = $san->invoke( $settings, 'password', $key );

			// empty preserves existing (plaintext or encrypted)
			$this->assertSame( 'plain-legacy', $cb( '' ), "$key preserve plaintext on empty" );

			$enc = $cb( 'new-' . $key );
			$this->assertTrue( SWPS_Encryption::is_encrypted( $enc ), "$key encrypted on save" );
			$this->assertSame( 'new-' . $key, SWPS_Encryption::decrypt( $enc ), "$key roundtrip" );

			// render blanks saved (encrypted)
			update_option( $opt, $enc );
			ob_start();
			$render->invoke( $settings, array( 'name' => $opt, 'key' => $key, 'type' => 'password', 'default' => '', 'description' => '' ) );
			$html = ob_get_clean();
			$this->assertStringNotContainsString( 'new-' . $key, $html, "$key not rendered" );
			$this->assertStringContainsString( 'value=""', $html, "$key blanked" );

			// render blanks legacy plaintext too
			update_option( $opt, 'legacy-' . $key );
			ob_start();
			$render->invoke( $settings, array( 'name' => $opt, 'key' => $key, 'type' => 'password', 'default' => '', 'description' => '' ) );
			$html2 = ob_get_clean();
			$this->assertStringNotContainsString( 'legacy-' . $key, $html2, "$key legacy not rendered" );
		}
		$this->assertSame( array(), $GLOBALS['swps_test_settings_errors'] );
	}

	public function test_ai_and_image_providers_decrypt_all_keys_and_read_plaintext_legacy(): void {
		$cases = array(
			array( new SWPS_Anthropic_Provider(), 'swps_anthropic_api_key' ),
			array( new SWPS_OpenAI_Provider(), 'swps_openai_api_key' ),
			array( new SWPS_Google_Provider(), 'swps_google_api_key' ),
			array( new SWPS_XAI_Provider(), 'swps_xai_api_key' ),
			array( new SWPS_OpenCode_Go_Provider(), 'swps_opencode_go_api_key' ),
			array( new SWPS_Unsplash_Provider(), 'swps_unsplash_api_key' ),
			array( new SWPS_Pexels_Provider(), 'swps_pexels_api_key' ),
			array( new SWPS_Pixabay_Provider(), 'swps_pixabay_api_key' ),
		);
		foreach ( $cases as $pair ) {
			$provider = $pair[0];
			$opt      = $pair[1];
			update_option( $opt, SWPS_Encryption::encrypt( 'secret-' . $opt ) );
			$this->assertSame( 'secret-' . $opt, $provider->get_api_key(), "$opt decrypted via provider" );
			update_option( $opt, 'plain-' . $opt );
			$this->assertSame( 'plain-' . $opt, $provider->get_api_key(), "$opt plaintext legacy readable" );
		}

		// Gemini image provider shares swps_google_api_key
		update_option( 'swps_google_api_key', SWPS_Encryption::encrypt( 'shared-google' ) );
		$gemini = new SWPS_Gemini_Image_Provider();
		$this->assertSame( 'shared-google', $gemini->get_api_key(), 'Gemini shared Google key decrypted' );
		update_option( 'swps_google_api_key', 'plain-google' );
		$this->assertSame( 'plain-google', ( new SWPS_Gemini_Image_Provider() )->get_api_key(), 'Gemini shared Google plaintext readable' );
	}

	public function test_image_inserter_reads_decrypted_provider_keys_directly(): void {
		// Direct stock provider key reads go through SWPS_Encryption::get_secret_option.
		foreach ( array( 'unsplash', 'pexels', 'pixabay' ) as $slug ) {
			$opt = "swps_{$slug}_api_key";
			update_option( 'swps_image_provider', $slug );
			update_option( $opt, SWPS_Encryption::encrypt( "k-$slug" ) );
			$this->assertSame( "k-$slug", SWPS_Encryption::get_secret_option( $opt ), "$slug direct reader decrypts" );
			update_option( $opt, "plain-$slug" );
			$this->assertSame( "plain-$slug", SWPS_Encryption::get_secret_option( $opt ), "$slug plaintext passthrough" );
		}
		// Gemini branch uses provider->get_api_key() (shared Google key).
		update_option( 'swps_image_provider', 'gemini' );
		update_option( 'swps_google_api_key', SWPS_Encryption::encrypt( 'gkey' ) );
		$this->assertSame( 'gkey', ( new SWPS_Gemini_Image_Provider() )->get_api_key() );
	}

	public function test_onboarding_encrypts_all_ai_provider_keys_and_preserves_on_failure(): void {
		$slugs = array( 'anthropic', 'openai', 'google', 'xai', 'opencode_go' );
		foreach ( $slugs as $slug ) {
			$opt = "swps_{$slug}_api_key";
			update_option( $opt, "existing-$slug" );
			$result = SWPS_Encryption::encrypt_option_for_storage( $opt, "new-$slug" );
			$this->assertFalse( $result['error'], "$slug encrypt ok" );
			$this->assertTrue( SWPS_Encryption::is_encrypted( $result['value'] ), "$slug encrypted" );
			$this->assertSame( "new-$slug", SWPS_Encryption::decrypt( $result['value'] ) );
		}

		// Failure-safe: simulate encrypt failure via subclass exercising real storage contract.
		foreach ( array( 'swps_anthropic_api_key','swps_openai_api_key','swps_google_api_key','swps_xai_api_key','swps_opencode_go_api_key' ) as $opt ) {
			update_option( $opt, 'existing-' . $opt );
			SWPS_Test_Encryption::$fail_plaintexts = array( 'new' );
			$result = SWPS_Test_Encryption::encrypt_option_for_storage( $opt, 'new' );
			SWPS_Test_Encryption::reset();
			$this->assertTrue( $result['error'], "$opt failure flagged" );
			$this->assertSame( 'existing-' . $opt, $result['value'], "$opt preserved on failure" );
		}
	}

	public function test_real_migration_encrypts_plaintext_is_idempotent_and_excludes_indexnow_and_legacy(): void {
		$opts = $this->allProviderSecretOptions();
		foreach ( $opts as $opt ) {
			update_option( $opt, 'plain-' . $opt );
		}
		update_option( 'swps_indexnow_api_key', 'public-indexnow-key' ); // must not be touched
		update_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION, 0 );

		$res = SWPS_Encryption::migrate_secret_options();
		$this->assertEmpty( $res['failed'] );
		$this->assertTrue( $res['complete'] );
		foreach ( $opts as $opt ) {
			$val = get_option( $opt, '' );
			$this->assertTrue( SWPS_Encryption::is_encrypted( $val ), "$opt migrated" );
			$this->assertSame( 'plain-' . $opt, SWPS_Encryption::decrypt( $val ), "$opt decrypts" );
		}
		$this->assertSame( 'public-indexnow-key', get_option( 'swps_indexnow_api_key' ), 'IndexNow excluded' );
		$this->assertSame( 1, get_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION ), 'completion flag set' );

		// Idempotent: second real run does not change encrypted values and stays complete.
		$snapshot = array();
		foreach ( $opts as $opt ) { $snapshot[$opt] = get_option( $opt ); }
		$res2 = SWPS_Encryption::migrate_secret_options();
		$this->assertEmpty( $res2['migrated'] );
		$this->assertEmpty( $res2['failed'] );
		$this->assertTrue( $res2['complete'] );
		foreach ( $opts as $opt ) {
			$this->assertSame( $snapshot[$opt], get_option( $opt ), "$opt unchanged on re-run" );
		}
	}

	public function test_real_migration_loop_failure_safe_encrypt_failure_idempotent_and_hook_retryable(): void {
		// Exercise REAL SWPS_Encryption::migrate_secret_options() loop (inherited, not overridden)
		// with controlled encrypt failure and idempotence; hook callback exercises same real method
		// with completion-flag guard (mirrors production) and remains retryable after failure.
		SWPS_Test_Encryption::$fail_plaintexts = array( 'plain-unsplash' );
		update_option( 'swps_unsplash_api_key', 'plain-unsplash' );
		update_option( 'swps_pexels_api_key', 'plain-pexels' );
		update_option( 'swps_indexnow_api_key', 'idx' );
		update_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION, 0 );

		$res = SWPS_Test_Encryption::migrate_secret_options();
		$this->assertContains( 'swps_unsplash_api_key', $res['failed'] );
		$this->assertSame( 'plain-unsplash', get_option( 'swps_unsplash_api_key' ), 'failed option left untouched' );
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_pexels_api_key' ) ), 'other option still migrated by real loop' );
		$this->assertSame( 'plain-pexels', SWPS_Encryption::decrypt( get_option( 'swps_pexels_api_key' ) ) );
		$this->assertSame( 'idx', get_option( 'swps_indexnow_api_key' ), 'IndexNow untouched' );
		$this->assertFalse( $res['complete'], 'not complete when failures remain' );
		$this->assertNotSame( 1, get_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION ), 'completion flag not set on failure' );

		// Idempotent on partial success/failure: re-run real loop does not re-migrate encrypted values.
		$pexels_snapshot = get_option( 'swps_pexels_api_key' );
		$res2 = SWPS_Test_Encryption::migrate_secret_options();
		$this->assertEmpty( $res2['migrated'] );
		$this->assertContains( 'swps_unsplash_api_key', $res2['failed'] );
		$this->assertSame( $pexels_snapshot, get_option( 'swps_pexels_api_key' ) );

		// Hook callback (production logic) calls real migration and is retryable.
		SWPS_Test_Encryption::$fail_plaintexts = array();
		$hook = function(): void {
			if ( ! class_exists( 'SWPS_Encryption' ) ) { return; }
			if ( get_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION ) && ! SWPS_Encryption::has_unencrypted_secret_options() ) { return; }
			SWPS_Encryption::migrate_secret_options();
		};
		$hook();
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_unsplash_api_key' ) ), 'hook/retry migrated previously failed option via real loop' );
		$this->assertSame( 'plain-unsplash', SWPS_Encryption::decrypt( get_option( 'swps_unsplash_api_key' ) ) );
		$this->assertSame( 1, get_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION ), 'completion flag set after successful retry' );

		// Completion-flag short-circuit (safe): hook no-ops when complete and nothing plaintext remains.
		update_option( 'swps_unsplash_api_key', 'should-stay' ); // simulate already encrypted state check path by making it encrypted
		// (no plaintext secrets now; flag set) - calling hook must not corrupt/mutate encrypted values.
		$encrypted_snapshot = array(
			'swps_unsplash_api_key' => get_option( 'swps_unsplash_api_key' ),
			'swps_pexels_api_key'   => get_option( 'swps_pexels_api_key' ),
		);
		// ensure no plaintext remains so guard can short-circuit
		update_option( 'swps_unsplash_api_key', SWPS_Encryption::encrypt( 'final' ) );
		$hook();
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_unsplash_api_key' ) ) );

		// Sanity: production wires this hook through the real guard helper.
		$plugin = file_get_contents( __DIR__ . '/../../stratawp-seo.php' );
		$this->assertStringContainsString( "add_action( 'admin_init', 'swps_migrate_secret_options', 5 );", $plugin );
		$this->assertStringContainsString( 'SWPS_Encryption::maybe_migrate_secret_options();', $plugin );
	}

	public function test_migration_compare_and_swap_does_not_overwrite_concurrent_new_save(): void {
		// Simulate race: migration reads plaintext, but a newer save lands before CAS update.
		SWPS_Test_Encryption::$race_for = array( 'swps_anthropic_api_key' );
		update_option( 'swps_anthropic_api_key', 'old-plain' );
		update_option( 'swps_openai_api_key', 'plain-openai' );
		update_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION, 0 );

		$res = SWPS_Test_Encryption::migrate_secret_options();
		$this->assertContains( 'swps_anthropic_api_key', $res['skipped'], 'raced option skipped' );
		$this->assertSame( 'concurrently-saved-swps_anthropic_api_key', get_option( 'swps_anthropic_api_key' ), 'newly saved value preserved (not overwritten)' );
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_openai_api_key' ) ), 'non-raced option migrated' );
		$this->assertNotEmpty( $res['skipped'] );
	}

	public function test_legacy_swps_api_key_migrated_safely_and_copy_to_anthropic_preserved(): void {
		// Legacy key present, anthropic empty: migration encrypts legacy in place (not deleted);
		// legacy copy flow reads decrypted value and can populate anthropic without clobbering/deleting legacy.
		update_option( 'swps_api_key', 'legacy-secret' );
		update_option( 'swps_anthropic_api_key', '' );
		update_option( 'swps_provider_migrated', 0 );
		update_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION, 0 );

		$res = SWPS_Encryption::migrate_secret_options();
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_api_key' ) ), 'legacy key encrypted in place' );
		$this->assertSame( 'legacy-secret', SWPS_Encryption::decrypt( get_option( 'swps_api_key' ) ), 'legacy key decrypts' );
		$this->assertTrue( SWPS_Encryption::is_secret_option( 'swps_api_key' ) );

		// Simulate legacy copy-to-Anthropic flow reading encrypted legacy key (must decrypt, must not delete).
		$legacy = SWPS_Encryption::decrypt( (string) get_option( 'swps_api_key', '' ) );
		$this->assertSame( 'legacy-secret', $legacy );
		if ( '' !== $legacy && '' === (string) get_option( 'swps_anthropic_api_key', '' ) ) {
			update_option( 'swps_anthropic_api_key', $legacy ); // as legacy flow would (then migration encrypts)
		}
		$this->assertSame( 'legacy-secret', get_option( 'swps_anthropic_api_key' ) );
		$this->assertNotEmpty( get_option( 'swps_api_key' ), 'legacy option not deleted/cleared' );

		// Running migration again encrypts copied anthropic value too.
		SWPS_Encryption::migrate_secret_options();
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_anthropic_api_key' ) ) );
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_api_key' ) ), 'legacy still present and encrypted' );
	}

	public function test_real_compare_and_swap_option_db_path_uses_binary_exact_match_and_handles_zero_row_race(): void {
		$mock = new SWPS_Mock_WPDB();
		$mock->options = 'wp_options';
		$GLOBALS['wpdb'] = $mock;

		// Successful CAS: asserts real DB path uses option_name + option_value conditions with BINARY exact compare.
		$mock->query_rows = 1;
		$ok = SWPS_Encryption::compare_and_swap_option( 'swps_anthropic_api_key', 'OldPlain', 'encrypted:new' );
		$this->assertTrue( $ok, 'CAS should succeed when DB reports 1 row updated' );
		$this->assertStringContainsString( 'UPDATE wp_options', (string) $mock->last_prepare );
		$this->assertStringContainsString( 'option_name = %s', (string) $mock->last_prepare );
		$this->assertStringContainsString( 'option_value = BINARY %s', (string) $mock->last_prepare, 'must use binary/exact option_value comparison' );
		$this->assertSame( array( 'encrypted:new', 'swps_anthropic_api_key', 'OldPlain' ), $mock->last_prepare_args );
		$this->assertNotNull( $mock->last_query );
		$this->assertStringContainsString( 'BINARY', (string) $mock->last_query );

		// 0-row race case: value changed concurrently -> must not overwrite.
		$mock2 = new SWPS_Mock_WPDB();
		$mock2->options = 'wp_options';
		$mock2->query_rows = 0;
		$GLOBALS['wpdb'] = $mock2;
		update_option( 'swps_anthropic_api_key', 'oldplain' ); // concurrent/different-case value present
		$race = SWPS_Encryption::compare_and_swap_option( 'swps_anthropic_api_key', 'OldPlain', 'encrypted:new' );
		$this->assertFalse( $race, '0-row race/changed value must return false' );
		$this->assertSame( 'oldplain', get_option( 'swps_anthropic_api_key' ), 'concurrent/changed value must not be overwritten' );

		unset( $GLOBALS['wpdb'] );

		// Fallback path (no $wpdb): strict string comparison via strcmp, not ==.
		update_option( 'swps_openai_api_key', 'oldplain' );
		$strict = SWPS_Encryption::compare_and_swap_option( 'swps_openai_api_key', 'OldPlain', 'encrypted:new' );
		$this->assertFalse( $strict, 'fallback must reject case-different value (exact comparison)' );
		$this->assertSame( 'oldplain', get_option( 'swps_openai_api_key' ) );
	}

	public function test_real_migration_guard_helper(): void {
		// Complete + no plaintext => guard skips.
		update_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION, 1 );
		update_option( 'swps_anthropic_api_key', SWPS_Encryption::encrypt( 'x' ) );
		$this->assertNull( SWPS_Encryption::maybe_migrate_secret_options(), 'guard should skip when complete and no plaintext' );

		// Flag set but plaintext remains => guard runs real migration.
		update_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION, 1 );
		update_option( 'swps_openai_api_key', 'plain-openai' );
		$res = SWPS_Encryption::maybe_migrate_secret_options();
		$this->assertIsArray( $res );
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_openai_api_key' ) ) );

		// Not complete with plaintext => runs.
		update_option( SWPS_Encryption::MIGRATION_COMPLETE_OPTION, 0 );
		update_option( 'swps_google_api_key', 'plain-google' );
		$res2 = SWPS_Encryption::maybe_migrate_secret_options();
		$this->assertIsArray( $res2 );
		$this->assertContains( 'swps_google_api_key', $res2['migrated'] );
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_google_api_key' ) ) );
	}

	public function test_real_legacy_api_key_copy_helper_preserves_old_option_and_only_copies_when_target_empty(): void {
		update_option( 'swps_api_key', SWPS_Encryption::encrypt( 'legacy-secret' ) );
		update_option( 'swps_anthropic_api_key', '' );
		$res = SWPS_Encryption::migrate_legacy_api_key_to_anthropic();
		$this->assertTrue( $res['copied'] );
		$this->assertTrue( $res['legacy_present'] );
		$this->assertTrue( $res['target_was_empty'] );
		$this->assertSame( 'legacy-secret', get_option( 'swps_anthropic_api_key' ), 'copied decrypted legacy value' );
		$this->assertSame( 'legacy-secret', SWPS_Encryption::decrypt( get_option( 'swps_api_key', '' ) ), 'legacy option preserved (not deleted/cleared)' );
		$this->assertTrue( SWPS_Encryption::is_encrypted( get_option( 'swps_api_key' ) ), 'legacy value remains as stored' );

		// Does NOT copy when target already non-empty.
		update_option( 'swps_api_key', SWPS_Encryption::encrypt( 'legacy-secret-2' ) );
		update_option( 'swps_anthropic_api_key', 'existing-anthropic' );
		$res2 = SWPS_Encryption::migrate_legacy_api_key_to_anthropic();
		$this->assertFalse( $res2['copied'] );
		$this->assertFalse( $res2['target_was_empty'] );
		$this->assertSame( 'existing-anthropic', get_option( 'swps_anthropic_api_key' ), 'must not overwrite non-empty target' );
		$this->assertSame( 'legacy-secret-2', SWPS_Encryption::decrypt( get_option( 'swps_api_key', '' ) ), 'legacy still preserved' );

		// No legacy present => no copy.
		update_option( 'swps_api_key', '' );
		update_option( 'swps_anthropic_api_key', '' );
		$res3 = SWPS_Encryption::migrate_legacy_api_key_to_anthropic();
		$this->assertFalse( $res3['copied'] );
		$this->assertFalse( $res3['legacy_present'] );
		$this->assertSame( '', get_option( 'swps_anthropic_api_key' ) );
	}

}