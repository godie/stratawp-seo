<?php
/**
 * OpenCode Go AI provider (Chat Completions).
 *
 * @package StrataWP_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SWPS_OpenCode_Go_Provider extends SWPS_AI_Provider {

	private const API_URL    = 'https://opencode.ai/inference/go/openai/v1/chat/completions';
	private const MODELS_URL = 'https://opencode.ai/inference/go/v1/models';

	/**
	 * Get a stable per-site session ID for OpenCode Go requests.
	 *
	 * Derived deterministically from the site identity so all requests from
	 * the same site share a stable session while different sites differ.
	 */
	public function get_session_id(): string {
		return self::derive_site_session_id();
	}

	/**
	 * Derive a stable UUID-format session ID for this site.
	 */
	public static function derive_site_session_id(): string {
		$site = '';
		if ( function_exists( 'home_url' ) ) {
			$site = (string) home_url( '/' );
		} elseif ( function_exists( 'get_option' ) ) {
			$site = (string) get_option( 'home', '' );
			if ( '' === $site ) {
				$site = (string) get_option( 'siteurl', '' );
			}
		}
		if ( '' === $site && defined( 'ABSPATH' ) ) {
			$site = (string) ABSPATH;
		}

		$hash = md5( 'swps-opencode-go-session|' . $site );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 );
	}

	/**
	 * Build request headers for OpenCode Go API calls.
	 *
	 * @param string $api_key API key.
	 * @return array<string, string>
	 */
	public function get_request_headers( string $api_key ): array {
		return array(
			'Content-Type'         => 'application/json',
			'Authorization'        => 'Bearer ' . $api_key,
			'x-opencode-session'   => $this->get_session_id(),
		);
	}

	public function get_slug(): string {
		return 'opencode_go';
	}

	public function get_name(): string {
		return 'OpenCode Go';
	}

	public function get_api_key_url(): string {
		return 'https://opencode.ai/docs/console/go/';
	}

	/**
	 * Curated Chat Completions models available on OpenCode Go.
	 *
	 * @return array<string, string>
	 */
	public function get_available_models(): array {
		return array(
			'deepseek-v4.1-flash'     => 'DeepSeek V4.1 Flash 1017',
			'deepseek-v4-pro'         => 'DeepSeek V4 Pro',
			'glm-5.2'                 => 'GLM-5.2',
			'glm-5.3'                 => 'GLM-5.3',
			'glm-5.3-flash'           => 'GLM-5.3-Flash',
			'hy3'                     => 'Hy3',
			'hy4-preview'             => 'Hy4 preview',
			'kimi-k2.7-code'          => 'Kimi K2.7 Code',
			'kimi-k3'                 => 'Kimi K3',
			'mimo-v2.6-flash'         => 'MiMo-V2.6-Flash',
			'mimo-v2.6-pro'           => 'MiMo-V2.6-Pro',
			'minimax-m3.1-flash'      => 'MiniMax M3.1 Flash',
			'longcat-2.0'             => 'LongCat-2.0',
			'step-5-preview'          => 'Step 5 Preview',
			'beam-free'               => 'Beam Free',
			'kimi-k3.1-pro'           => 'Kimi K3.1 Pro',
			'iceman-contributor-free' => 'Iceman Contributor Free',
			'hy4'                     => 'Hy4',
			'longcat-2.5-free'        => 'LongCat 2.5 Free',
		);
	}

	public function chat( string $system_prompt, string $user_message, int $max_tokens = 4096 ): string|WP_Error {
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error( 'swps_no_api_key', __( 'Please enter your OpenCode Go API key in StrataWP SEO settings.', 'stratawp-seo' ) );
		}

		$body = array(
			'model'      => $this->get_validated_model(),
			'max_tokens' => $max_tokens,
			'messages'   => array(
				array(
					'role'    => 'system',
					'content' => $system_prompt,
				),
				array(
					'role'    => 'user',
					'content' => $user_message,
				),
			),
		);

		if ( $this->requesting_json ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 180,
				'headers' => $this->get_request_headers( $api_key ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'swps_api_request_failed',
				sprintf( __( 'API request failed: %s', 'stratawp-seo' ), $response->get_error_message() )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$decoded     = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_message = $decoded['error']['message'] ?? __( 'Unknown API error.', 'stratawp-seo' );
			return new WP_Error(
				'swps_api_error',
				sprintf( __( 'OpenCode Go API error (%1$d): %2$s', 'stratawp-seo' ), $status_code, $error_message ),
				array( 'status' => (int) $status_code )
			);
		}

		if ( empty( $decoded['choices'][0]['message']['content'] ) ) {
			return new WP_Error( 'swps_empty_response', __( 'Received empty response from OpenCode Go.', 'stratawp-seo' ) );
		}

		if ( ! empty( $decoded['usage'] ) ) {
			$this->last_usage = array(
				'input_tokens'  => (int) ( $decoded['usage']['prompt_tokens'] ?? 0 ),
				'output_tokens' => (int) ( $decoded['usage']['completion_tokens'] ?? 0 ),
			);
		}

		$finish_reason          = $decoded['choices'][0]['finish_reason'] ?? null;
		$this->last_stop_reason = ( $finish_reason === 'length' ) ? 'max_tokens' : $finish_reason;

		return $decoded['choices'][0]['message']['content'];
	}

	/**
	 * Fetch live Chat Completions models from the OpenCode Go /v1/models endpoint.
	 *
	 * @return array<string, string> Model ID => display name (empty on error).
	 */
	public function fetch_remote_models(): array {
		$api_key = $this->get_api_key();
		if ( empty( $api_key ) ) {
			return array();
		}
		$response = wp_remote_get(
			self::MODELS_URL,
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		return self::parse_models_response( (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
	}

	/**
	 * Keep only models that expose the Chat Completions API.
	 *
	 * @param array $body Decoded response body.
	 * @return array<string, string>
	 */
	public static function parse_models_response( array $body ): array {
		$models = array();
		foreach ( $body['data'] ?? array() as $m ) {
			$id  = (string) ( $m['id'] ?? '' );
			$api = $m['api'] ?? array();
			if ( '' === $id || ! is_array( $api ) || ! isset( $api['chat-completions'] ) ) {
				continue;
			}
			$models[ $id ] = (string) ( $m['name'] ?? $id );
		}
		return $models;
	}

	public function test_key( string $api_key ): bool|WP_Error {
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 30,
				'headers' => $this->get_request_headers( $api_key ),
				'body'    => wp_json_encode(
					array(
						'model'      => $this->get_validated_model(),
						'max_tokens' => 10,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'Say "ok"',
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $status_code ) {
			return true;
		}

		$body          = json_decode( wp_remote_retrieve_body( $response ), true );
		$error_message = $body['error']['message'] ?? 'Invalid API key.';

		return new WP_Error( 'swps_invalid_key', $error_message );
	}
}
