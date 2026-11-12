<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-ai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-opencode-go-provider.php';

final class OpenCodeGoProviderTest extends TestCase {

	public function test_slug_name_and_key_url(): void {
		$provider = new SWPS_OpenCode_Go_Provider();
		$this->assertSame( 'opencode_go', $provider->get_slug() );
		$this->assertSame( 'OpenCode Go', $provider->get_name() );
		$this->assertSame( 'https://opencode.ai/docs/console/go/', $provider->get_api_key_url() );
	}

	public function test_curated_models_include_19_chat_completions(): void {
		$provider = new SWPS_OpenCode_Go_Provider();
		$models   = $provider->get_available_models();
		$expected = array(
			'deepseek-v4.1-flash',
			'deepseek-v4-pro',
			'glm-5.2',
			'glm-5.3',
			'glm-5.3-flash',
			'hy3',
			'hy4-preview',
			'kimi-k2.7-code',
			'kimi-k3',
			'mimo-v2.6-flash',
			'mimo-v2.6-pro',
			'minimax-m3.1-flash',
			'longcat-2.0',
			'step-5-preview',
			'beam-free',
			'kimi-k3.1-pro',
			'iceman-contributor-free',
			'hy4',
			'longcat-2.5-free',
		);
		$this->assertCount( 19, $models );
		$this->assertSame( $expected, array_keys( $models ) );
		$this->assertSame( 'Iceman Contributor Free', $models['iceman-contributor-free'] );
	}

	public function test_api_key_option_uses_provider_slug(): void {
		$provider = new SWPS_OpenCode_Go_Provider();
		$this->assertSame( 'swps_opencode_go_api_key', $provider->get_api_key_option() );
	}

	public function test_request_headers_include_stable_per_site_session_and_auth(): void {
		$expected = SWPS_OpenCode_Go_Provider::derive_site_session_id();
		$provider = new SWPS_OpenCode_Go_Provider();
		$headers  = $provider->get_request_headers( 'test-key' );

		$this->assertSame( 'application/json', $headers['Content-Type'] ?? null );
		$this->assertSame( 'Bearer test-key', $headers['Authorization'] ?? null );
		$this->assertArrayHasKey( 'x-opencode-session', $headers );
		$this->assertSame( $expected, $headers['x-opencode-session'] );
		$this->assertSame( $expected, $provider->get_session_id() );
		$this->assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
			(string) $headers['x-opencode-session']
		);

		$provider_b = new SWPS_OpenCode_Go_Provider();
		$this->assertSame( $expected, $provider_b->get_session_id(), 'Session ID must be stable per site across instances.' );
	}
}
