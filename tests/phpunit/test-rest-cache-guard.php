<?php
/**
 * REST cache guard tests (2.0.2)
 *
 * Every suite REST response, free and addon namespaces alike, carries
 * LiteSpeed's no-cache header and fires LiteSpeed Cache's no-cache
 * control action; other namespaces are untouched. Customer report: a
 * LiteSpeed private cache served the designer a stale template, so the
 * next save hit the conflict check.
 *
 * @package PressPrimer_Certificate
 * @subpackage Tests
 * @since 2.0.2
 */

use PHPUnit\Framework\TestCase;

/**
 * REST cache guard test case
 *
 * @since 2.0.2
 */
class Test_REST_Cache_Guard extends TestCase {

	/**
	 * Reasons LiteSpeed Cache's control action received.
	 *
	 * @var string[]
	 */
	private $nocache_reasons = [];

	/**
	 * Reset hooks and spy on the LiteSpeed control action.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		ppcert_tests_reset_hooks();
		$this->nocache_reasons = [];

		add_action(
			'litespeed_control_set_nocache',
			function ( $reason ) {
				$this->nocache_reasons[] = $reason;
			}
		);
	}

	/**
	 * Dispatch a response through the guard for a route.
	 *
	 * @param string $route Route.
	 * @return WP_REST_Response
	 */
	private function dispatch( $route ) {
		$request = new WP_REST_Request();
		$request->set_route( $route );

		return PressPrimer_Certificate_REST_Cache_Guard::mark_uncacheable(
			new WP_REST_Response( [ 'ok' => true ] ),
			null,
			$request
		);
	}

	/**
	 * Init registers on rest_post_dispatch with the request argument.
	 *
	 * @return void
	 */
	public function test_init_registers_the_dispatch_filter() {
		PressPrimer_Certificate_REST_Cache_Guard::init();

		$entries = $GLOBALS['ppcert_test_hooks']['rest_post_dispatch'];

		$this->assertCount( 1, $entries );
		$this->assertSame( [ 'PressPrimer_Certificate_REST_Cache_Guard', 'mark_uncacheable' ], $entries[0]['callback'] );
		$this->assertSame( 3, $entries[0]['accepted_args'] );
	}

	/**
	 * The designer's template load (the customer's stale request) is
	 * marked not cacheable both ways.
	 *
	 * @return void
	 */
	public function test_free_route_is_marked_not_cacheable() {
		$response = $this->dispatch( '/ppcert/v1/templates/6' );

		$this->assertSame( 'no-cache', $response->get_headers()['X-LiteSpeed-Cache-Control'] );
		$this->assertSame( [ 'PressPrimer Certificate REST response' ], $this->nocache_reasons );
		$this->assertSame( [ 'ok' => true ], $response->get_data() );
	}

	/**
	 * Every addon namespace is covered by the one free change.
	 *
	 * @return void
	 */
	public function test_addon_namespaces_are_marked_not_cacheable() {
		foreach ( [ '/ppcert-educator/v1/bulk-awards/3', '/ppcert-school/v1/retroactive/2', '/ppcert-enterprise/v1/verify/AB12CD34' ] as $route ) {
			$response = $this->dispatch( $route );

			$this->assertSame( 'no-cache', $response->get_headers()['X-LiteSpeed-Cache-Control'], $route );
		}

		$this->assertCount( 3, $this->nocache_reasons );
	}

	/**
	 * Other namespaces, and look-alike prefixes, are left alone.
	 *
	 * @return void
	 */
	public function test_other_routes_are_untouched() {
		foreach ( [ '/wp/v2/media/548', '/ppcertificates/v1/items', '/', '' ] as $route ) {
			$response = $this->dispatch( $route );

			$this->assertArrayNotHasKey( 'X-LiteSpeed-Cache-Control', $response->get_headers(), $route );
		}

		$this->assertSame( [], $this->nocache_reasons );
	}

	/**
	 * A missing request (another filter's odd call) passes through.
	 *
	 * @return void
	 */
	public function test_without_a_request_the_response_passes_through() {
		$response = new WP_REST_Response( [ 'ok' => true ] );

		$this->assertSame( $response, PressPrimer_Certificate_REST_Cache_Guard::mark_uncacheable( $response, null, null ) );
		$this->assertSame( [], $this->nocache_reasons );
	}
}
