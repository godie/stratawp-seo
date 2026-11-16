<?php
/**
 * Unit tests: provider API keys travel in the x-goog-api-key header, never in
 * the request URL (URLs are logged by proxies, servers and error trackers).
 *
 * @package StrataWP_SEO
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/class-encryption.php';
require_once __DIR__ . '/../../includes/class-ai-provider.php';
require_once __DIR__ . '/../../includes/providers/ai/class-google-provider.php';
require_once __DIR__ . '/../../includes/class-image-provider.php';
require_once __DIR__ . '/../../includes/providers/images/class-gemini-provider.php';

/**
 * Google provider with a fixed key and model so requests can be inspected
 * without loading the model-discovery stack.
 */
final class SWPS_Test_Google_Provider extends SWPS_Google_Provider {

	public function get_api_key(): string {
		return 'test-google-key-value';
	}

	public function get_validated_model(): string {
		return 'gemini-2.0-flash';
	}
}

final class SecurityArsenalProviderKeysTest extends TestCase {

	private const KEY = 'test-google-key-value';

	protected function setUp(): void {
		$GLOBALS['swps_test_options']       = array();
		$GLOBALS['swps_test_http_log']      = array();
		$GLOBALS['swps_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"candidates":[{"content":{"parts":[{"text":"ok"}]}}]}',
		);
	}

	/**
	 * @return array{method:string,url:string,args:array}
	 */
	private function last_request(): array {
		$log = $GLOBALS['swps_test_http_log'] ?? array();
		$this->assertNotEmpty( $log, 'expected an outbound HTTP request to be captured' );
		return $log[ count( $log ) - 1 ];
	}

	/**
	 * The key must ride in the header and must be absent from the URL.
	 */
	private function assert_key_in_header_not_url( array $request ): void {
		$this->assertStringNotContainsString( self::KEY, $request['url'], 'API key must never appear in the request URL' );
		$this->assertStringNotContainsString( 'key=', $request['url'], 'API key must never be sent as a query parameter' );
		$headers = $request['args']['headers'] ?? array();
		$this->assertArrayHasKey( 'x-goog-api-key', $headers, 'request must authenticate via x-goog-api-key' );
		$this->assertSame( self::KEY, $headers['x-goog-api-key'] );
	}

	public function test_chat_sends_key_in_header_and_not_in_url(): void {
		$provider = new SWPS_Test_Google_Provider();
		$result   = $provider->chat( 'system', 'user' );

		$this->assertSame( 'ok', $result );
		$this->assert_key_in_header_not_url( $this->last_request() );
		$this->assertStringContainsString( ':generateContent', $this->last_request()['url'] );
	}

	public function test_search_grounded_sends_key_in_header_and_not_in_url(): void {
		$provider = new SWPS_Test_Google_Provider();
		$result   = $provider->search_grounded( 'query' );

		$this->assertIsArray( $result );
		$this->assert_key_in_header_not_url( $this->last_request() );
	}

	public function test_test_key_sends_key_in_header_and_not_in_url(): void {
		$provider = new SWPS_Test_Google_Provider();
		$this->assertTrue( $provider->test_key( self::KEY ) );
		$this->assert_key_in_header_not_url( $this->last_request() );
	}

	public function test_google_model_list_sends_key_in_header_and_not_in_url(): void {
		$GLOBALS['swps_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"models":[{"name":"models/gemini-2.0-flash","displayName":"Gemini 2.0 Flash","supportedGenerationMethods":["generateContent"]}]}',
		);
		$provider = new SWPS_Test_Google_Provider();
		$models   = $provider->fetch_remote_models();

		$this->assertArrayHasKey( 'gemini-2.0-flash', $models );
		$request = $this->last_request();
		$this->assertSame( 'GET', $request['method'] );
		$this->assert_key_in_header_not_url( $request );
	}

	public function test_gemini_image_generation_sends_key_in_header_and_not_in_url(): void {
		$GLOBALS['swps_test_http_response'] = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"candidates":[]}',
		);
		update_option( 'swps_gemini_image_model', 'gemini-3.1-flash-image-preview' );

		$provider = new SWPS_Gemini_Image_Provider();
		$result   = $provider->generate_image( 'a cat', self::KEY, '1:1' );

		$this->assertInstanceOf( WP_Error::class, $result, 'stub body has no image data' );
		$request = $this->last_request();
		$this->assertSame( 'POST', $request['method'] );
		$this->assert_key_in_header_not_url( $request );
		$this->assertStringContainsString( ':generateContent', $request['url'] );
	}

	/**
	 * Regression guard: no Google caller may reintroduce the query-string key.
	 */
	public function test_provider_sources_contain_no_query_string_api_key(): void {
		$files = array(
			__DIR__ . '/../../includes/providers/ai/class-google-provider.php',
			__DIR__ . '/../../includes/providers/images/class-gemini-provider.php',
		);
		foreach ( $files as $file ) {
			$source = (string) file_get_contents( $file );
			$this->assertDoesNotMatchRegularExpression( '/[?&]key=/', $source, basename( $file ) . ' must not build a ?key= URL' );
		}
	}
}

