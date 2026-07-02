<?php
/**
 * Tests for the uninstall option-cleanup seam.
 *
 * @package Newspack_Cache_Cozy\Tests\Unit
 */

declare( strict_types = 1 );

namespace Newspack_Cache_Cozy\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once \dirname( __DIR__ ) . '/includes/uninstall-cleanup.php';

final class UninstallCleanupTest extends TestCase {

	/** Minimal wpdb double: get_col resolves a prefix LIKE against _wp_options. */
	private function wpdb(): object {
		return new class() {
			public string $options = 'wp_options';
			public function esc_like( string $text ): string {
				return $text;
			}
			public function prepare( string $query, mixed $arg ): string {
				return \str_replace( '%s', "'" . (string) $arg . "'", $query );
			}
			public function get_col( string $query ): array {
				\preg_match( "/LIKE '([^']*)'/", $query, $m );
				$prefix = \rtrim( $m[1] ?? '', '%' );
				return \array_values( \array_filter(
					\array_keys( $GLOBALS['_wp_options'] ),
					static fn ( string $name ): bool => \str_starts_with( $name, $prefix )
				) );
			}
		};
	}

	protected function setUp(): void {
		$GLOBALS['_wp_options'] = [];
	}

	public function test_deletes_prefixed_options_and_their_transients_only(): void {
		$GLOBALS['_wp_options'] = [
			'newspack_cache_cozy_topologies'                    => [ 't' ],
			'newspack_cache_cozy_base_directory'                => '/tmp',
			'_transient_newspack_cache_cozy_lock'               => 1,
			'_transient_timeout_newspack_cache_cozy_lock'       => 123,
			'other_plugin_option'                          => 'keep',
			'siteurl'                                      => 'https://example.test',
		];

		$deleted = \Newspack_Cache_Cozy\delete_prefixed_options( $this->wpdb(), 'newspack_cache_cozy_' );

		$this->assertSame( 4, $deleted );
		$this->assertArrayNotHasKey( 'newspack_cache_cozy_topologies', $GLOBALS['_wp_options'] );
		$this->assertArrayNotHasKey( '_transient_newspack_cache_cozy_lock', $GLOBALS['_wp_options'] );
		$this->assertArrayNotHasKey( '_transient_timeout_newspack_cache_cozy_lock', $GLOBALS['_wp_options'] );
		$this->assertSame( 'keep', $GLOBALS['_wp_options']['other_plugin_option'] );
		$this->assertSame( 'https://example.test', $GLOBALS['_wp_options']['siteurl'] );
	}

	public function test_returns_zero_when_nothing_matches(): void {
		$GLOBALS['_wp_options'] = [ 'siteurl' => 'https://example.test' ];

		$this->assertSame( 0, \Newspack_Cache_Cozy\delete_prefixed_options( $this->wpdb(), 'newspack_cache_cozy_' ) );
		$this->assertSame( 'https://example.test', $GLOBALS['_wp_options']['siteurl'] );
	}
}
