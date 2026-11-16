<?php
/**
 * PHPUnit bootstrap for pure-PHP scorer unit tests.
 *
 * These tests do NOT load WordPress. Anything requiring WP functions
 * should be tested via manual WP-CLI smoke (see Task 21).
 *
 * @package StrataWP_SEO
 */

// Minimal WP function stubs the scorers need at parse-time.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp-fake/' );
}

// WordPress time constants.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
    define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
    define( 'WEEK_IN_SECONDS', 604800 );
}

// wpdb result-format constants.
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( $string, $remove_breaks = false ) {
        $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
        $string = strip_tags( $string );
        if ( $remove_breaks ) {
            $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
        }
        return trim( $string );
    }
}

if ( ! function_exists( 'wp_parse_url' ) ) {
    function wp_parse_url( $url, $component = -1 ) {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( '__' ) ) {
    function __( $text, $domain = 'default' ) {
        return $text;
    }
}

// HTTP stubs shared by provider tests. Every request is appended to
// $GLOBALS['swps_test_http_log'] so tests can assert on URL and headers.
if ( ! function_exists( 'wp_remote_post' ) ) {
    function wp_remote_post( $url, $args = array() ) {
        $GLOBALS['swps_test_http_log'][] = array( 'method' => 'POST', 'url' => $url, 'args' => $args );
        $GLOBALS['swps_test_last_post']  = array( 'url' => $url, 'args' => $args );
        return $GLOBALS['swps_test_http_response'] ?? array( 'response' => array( 'code' => 200 ), 'body' => '' );
    }
}
if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( $url, $args = array() ) {
        $GLOBALS['swps_test_http_log'][] = array( 'method' => 'GET', 'url' => $url, 'args' => $args );
        $GLOBALS['swps_test_last_get']   = array( 'url' => $url, 'args' => $args );
        return $GLOBALS['swps_test_http_response'] ?? array( 'response' => array( 'code' => 200 ), 'body' => '' );
    }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $r ) {
        return $r['response']['code'] ?? 0;
    }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $r ) {
        return $r['body'] ?? '';
    }
}

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private string $code;
        private string $message;
        private $data;
        public function __construct( string $code = '', string $message = '', $data = null ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

require_once __DIR__ . '/../vendor/autoload.php';
