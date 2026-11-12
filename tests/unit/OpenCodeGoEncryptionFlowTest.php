<?php
use PHPUnit\Framework\TestCase;

// Minimal WP stubs for these tests.
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
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) { return true; }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) { return $value; }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) { return (string) $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) { return (string) $text; }
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $text ) { return (string) $text; }
}
if ( ! function_exists( 'register_setting' ) ) {
	function register_setting( $group, $name, $args = array() ) {}
}
if ( ! function_exists( 'add_settings_field' ) ) {
	function add_settings_field( $id, $title, $callback, $page, $section = 'default', $args = array() ) {}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $selected, $current, $echo = true ) { $r = ($selected == $current) ? ' selected="selected"' : ''; if($echo) echo $r; return $r; }
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $checked, $current = true, $echo = true ) { $r = ($checked == $current) ? ' checked="checked"' : ''; if($echo) echo $r; return $r; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) { return json_encode( $data ); }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

require_once __DIR__ . '/../../includes/class-encryption.php';
require_once __DIR__ . '/../../includes/class-settings.php';
require_once __DIR__ . '/../../includes/class-ai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-opencode-go-provider.php';

final class OpenCodeGoEncryptionFlowTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['swps_test_options'] = array();
		$GLOBALS['swps_test_settings_errors'] = array();
	}

	public function test_encryption_roundtrip_and_plaintext_migration(): void {
		$plain = 'sk-opencode-go-test';
		$enc   = SWPS_Encryption::encrypt( $plain );
		$this->assertNotSame( '', $enc );
		$this->assertTrue( SWPS_Encryption::is_encrypted( $enc ) );
		$this->assertSame( $plain, SWPS_Encryption::decrypt( $enc ) );
		$this->assertSame( $plain, SWPS_Encryption::decrypt( $plain ) ); // migration-safe
	}

	public function test_settings_sanitize_encrypts_preserves_empty_and_handles_encrypt_failure(): void {
		$settings = new SWPS_Settings();
		$ref = new ReflectionClass( $settings );
		$m = $ref->getMethod( 'get_sanitize_callback' );
		$m->setAccessible( true );
		$cb = $m->invoke( $settings, 'password', 'opencode_go_api_key' );

		$existing = SWPS_Encryption::encrypt( 'existing-secret' );
		update_option( 'swps_opencode_go_api_key', $existing );
		$this->assertSame( $existing, $cb( '' ) ); // preserve on empty
		$this->assertSame( array(), $GLOBALS['swps_test_settings_errors'] );

		$saved = $cb( 'new-secret' );
		$this->assertTrue( SWPS_Encryption::is_encrypted( $saved ) );
		$this->assertSame( 'new-secret', SWPS_Encryption::decrypt( $saved ) );
		$this->assertSame( array(), $GLOBALS['swps_test_settings_errors'] );

		// Encryption failure path: encrypt() returns '' for non-empty input -> preserve existing + settings error.
		$script = <<<'PHP'
<?php
$root = $argv[1] ?? getcwd();
if (!defined('ABSPATH')) define('ABSPATH','/tmp/wp-fake/');
function __($t,$d='default'){return $t;}
function sanitize_text_field($s){return is_string($s)?trim($s):'';}
$GLOBALS['swps_test_options']=['swps_opencode_go_api_key'=>'existing-plain'];
$GLOBALS['swps_test_settings_errors']=[];
function get_option($o,$d=false){return array_key_exists($o,$GLOBALS['swps_test_options'])?$GLOBALS['swps_test_options'][$o]:$d;}
function update_option($o,$v){$GLOBALS['swps_test_options'][$o]=$v;return true;}
function add_settings_error($setting,$code,$message,$type='error'){$GLOBALS['swps_test_settings_errors'][]=compact('setting','code','message','type');}
function add_action(){} function add_filter(){} function register_setting(){} function add_settings_field(){}
function esc_attr($t){return $t;} function esc_html__($t,$d='default'){return $t;} function esc_html($t){return $t;} function esc_textarea($t){return $t;} function selected(){} function checked(){}
class SWPS_Encryption {
	public static function secret_option_names(): array { return array('swps_opencode_go_api_key','swps_anthropic_api_key','swps_openai_api_key','swps_google_api_key','swps_xai_api_key','swps_unsplash_api_key','swps_pexels_api_key','swps_pixabay_api_key','swps_jon_ai_secret','swps_gsc_client_secret'); }
	public static function is_secret_option($o): bool { return in_array($o, self::secret_option_names(), true); }
	public static function encrypt($v){ return ''; }
	public static function decrypt($v){ return $v; }
	public static function is_encrypted($v){ return false; }
	public static function get_secret_option($o,$d=''){ $v=get_option($o,$d); return is_string($v)?$v:(string)$d; }
	public static function encrypt_option_for_storage($o,$v){ $existing=(string)get_option($o,''); if($v===null||$v===''){return array('value'=>$existing,'error'=>false);} $enc=self::encrypt((string)$v); if($enc===''){return array('value'=>$existing,'error'=>true);} return array('value'=>$enc,'error'=>false); }
}
require $root . '/includes/class-settings.php';
$settings=new SWPS_Settings();
$ref=new ReflectionClass($settings); $m=$ref->getMethod('get_sanitize_callback'); $m->setAccessible(true);
$cb=$m->invoke($settings,'password','opencode_go_api_key');
$r=$cb('new-secret');
echo json_encode(['r'=>$r,'e'=>$GLOBALS['swps_test_settings_errors']]);
PHP;
		$root = dirname(__DIR__, 2);
		$tmp = tempnam(sys_get_temp_dir(), 'swps_enc_fail_') . '.php';
		file_put_contents($tmp, $script);
		$out = shell_exec('cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' 2>&1');
		@unlink($tmp);
		$this->assertNotNull($out);
		$decoded = json_decode((string) $out, true);
		$this->assertIsArray($decoded, 'Expected JSON output, got: ' . (string) $out);
		$this->assertSame('existing-plain', $decoded['r'] ?? null); // preserved
		$this->assertNotEmpty($decoded['e'] ?? array()); // settings error surfaced
	}

	public function test_settings_render_blanks_saved_secret_field_plaintext_and_encrypted(): void {
		$settings = new SWPS_Settings();
		$ref = new ReflectionClass( $settings );
		$m = $ref->getMethod( 'render_field' );
		$m->setAccessible( true );

		update_option( 'swps_opencode_go_api_key', SWPS_Encryption::encrypt( 'secret' ) );
		ob_start();
		$m->invoke( $settings, array(
			'name' => 'swps_opencode_go_api_key', 'key' => 'opencode_go_api_key',
			'type' => 'password', 'default' => '', 'description' => '',
		) );
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'secret', $html );
		$this->assertStringContainsString( 'value=""', $html );
		$this->assertStringContainsString( 'Saved. Leave blank to keep current value.', $html );

		update_option( 'swps_opencode_go_api_key', 'plain-legacy' );
		ob_start();
		$m->invoke( $settings, array(
			'name' => 'swps_opencode_go_api_key', 'key' => 'opencode_go_api_key',
			'type' => 'password', 'default' => '', 'description' => '',
		) );
		$html2 = ob_get_clean();
		$this->assertStringNotContainsString( 'plain-legacy', $html2 );
		$this->assertStringContainsString( 'value=""', $html2 );
	}

	public function test_provider_decrypts_and_onboarding_save_encrypts_opencode_go_key_with_failure_guard(): void {
		$api_key   = 'onboarding-key';
		$encrypted = SWPS_Encryption::encrypt( $api_key );
		$this->assertTrue( SWPS_Encryption::is_encrypted( $encrypted ) );
		update_option( 'swps_opencode_go_api_key', $encrypted );

		$provider = new SWPS_OpenCode_Go_Provider();
		$this->assertSame( $api_key, $provider->get_api_key() ); // decrypts on read

		// Plaintext legacy remains readable (migration-safe).
		update_option( 'swps_opencode_go_api_key', 'plain-key' );
		$this->assertSame( 'plain-key', (new SWPS_OpenCode_Go_Provider())->get_api_key() );
		$this->assertSame( 'swps_opencode_go_api_key', $provider->get_api_key_option() );

		// Onboarding encryption + failure guard contract: opencode_go key must be stored encrypted;
		// on encrypt failure, onboarding must error and must not overwrite existing saved value.
		$script = <<<'PHP'
<?php
$root = $argv[1] ?? getcwd();
if (!defined('ABSPATH')) define('ABSPATH','/tmp/wp-fake/');
function __($t,$d='default'){return $t;}
function sanitize_text_field($s){return is_string($s)?trim($s):'';}
function sanitize_key($k){$k=strtolower((string)$k); return preg_replace('/[^a-z0-9_\-]/','',$k);}
function wp_unslash($v){return $v;}
$GLOBALS['swps_test_options']=['swps_opencode_go_api_key'=>'existing-enc'];
$GLOBALS['swps_json_errors']=[];
function get_option($o,$d=false){return array_key_exists($o,$GLOBALS['swps_test_options'])?$GLOBALS['swps_test_options'][$o]:$d;}
function update_option($o,$v){$GLOBALS['swps_test_options'][$o]=$v;return true;}
function wp_send_json_error($d=null){$GLOBALS['swps_json_errors'][]=$d; throw new RuntimeException('err');}
function wp_send_json_success($d=null){throw new RuntimeException('ok');}
function check_ajax_referer(){return true;} function current_user_can(){return true;} function add_action(){} function add_filter(){}
function wp_json_encode($d){return json_encode($d);} function is_wp_error($t){return $t instanceof WP_Error;}
class WP_Error{public function __construct($c='',$m=''){$this->m=$m;} public function get_error_message(){return $this->m;}}
class SWPS_Encryption {
	public static function secret_option_names(): array { return array('swps_opencode_go_api_key','swps_anthropic_api_key','swps_openai_api_key','swps_google_api_key','swps_xai_api_key','swps_unsplash_api_key','swps_pexels_api_key','swps_pixabay_api_key','swps_jon_ai_secret','swps_gsc_client_secret'); }
	public static function is_secret_option($o): bool { return in_array($o, self::secret_option_names(), true); }
	public static function encrypt($v){ return ''; }
	public static function decrypt($v){ return $v; }
	public static function is_encrypted($v){ return false; }
	public static function get_secret_option($o,$d=''){ $v=get_option($o,$d); return is_string($v)?$v:(string)$d; }
	public static function encrypt_option_for_storage($o,$v){ $existing=(string)get_option($o,''); if($v===null||$v===''){return array('value'=>$existing,'error'=>false);} $enc=self::encrypt((string)$v); if($enc===''){return array('value'=>$existing,'error'=>true);} return array('value'=>$enc,'error'=>false); }
}
require $root . '/includes/class-ai-provider.php';
class SWPS_OpenCode_Go_Provider extends SWPS_AI_Provider {
	public function get_slug(): string { return 'opencode_go'; }
	public function get_name(): string { return 'OpenCode Go'; }
	public function get_api_key_url(): string { return ''; }
	public function get_available_models(): array { return array('m'=>'M'); }
	public function chat(string $s,string $u,int $m=1): string|WP_Error { return ''; }
	public function test_key(string $api_key): bool|WP_Error { return true; }
}
require $root . '/includes/class-onboarding.php';
$_POST=['provider'=>'opencode_go','api_key'=>'new-key'];
$ob=new SWPS_Onboarding();
try { $ob->ajax_swps_onboarding_test_key(); } catch (RuntimeException $e) {}
echo json_encode(['err'=>$GLOBALS['swps_json_errors'],'saved'=>$GLOBALS['swps_test_options']['swps_opencode_go_api_key']??null]);
PHP;
		$root = dirname(__DIR__, 2);
		$tmp = tempnam(sys_get_temp_dir(), 'swps_onb_fail_') . '.php';
		file_put_contents($tmp, $script);
		$out = shell_exec('cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg($tmp) . ' ' . escapeshellarg($root) . ' 2>&1');
		@unlink($tmp);
		$this->assertNotNull($out);
		$decoded = json_decode((string) $out, true);
		$this->assertIsArray($decoded, 'Expected JSON output, got: ' . (string) $out);
		$this->assertNotEmpty($decoded['err'] ?? array(), 'Onboarding should error on encrypt failure');
		$this->assertSame('existing-enc', $decoded['saved'] ?? null, 'Existing key must be preserved on encrypt failure');
	}
}
