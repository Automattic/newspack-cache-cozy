<?php
/**
 * Tests for Newspack_Cache_Cozy\Cold_Read_Object_Cache.
 *
 * The cache-cozy's object-cache decorator. During an out-of-band warm
 * render we swap this in for $GLOBALS['wp_object_cache'] so that reads on
 * allowlisted "cold" groups always miss (forcing Newspack to rebuild every
 * block / re-run every ES query instead of serving stale HTML), while every
 * write passes straight through to the real object cache — so the freshly
 * rendered entries land in live memcached under their own correct keys with
 * a fresh timestamp. No key replication, no cold window.
 *
 * @package Newspack_Cache_Cozy
 */

namespace Newspack_Cache_Cozy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use Newspack_Cache_Cozy\Cold_Read_Object_Cache;
use Newspack_Nodes\Tests\TestCase;

#[CoversClass( Cold_Read_Object_Cache::class )]
class ColdReadObjectCacheTest extends TestCase {

	/**
	 * Minimal array-backed WP_Object_Cache double: group-namespaced get/set
	 * honoring the by-ref $found contract wp_cache_get() relies on, plus a
	 * passthrough-only method to prove __call delegation.
	 */
	private function fake_real_cache(): object {
		// Real WP_Object_Cache carries arbitrary public props (cache_hits,
		// global_groups, …); allow them on the double so property-delegation
		// tests don't trip PHP 8.4's dynamic-property deprecation.
		return new #[\AllowDynamicProperties] class() {
			public array $store    = [];
			public array $deleted  = [];
			public bool $fail_sets = false;
			public function get( $key, $group = '', $force = false, &$found = null ) {
				if ( isset( $this->store[ $group ][ $key ] ) ) {
					$found = true;
					return $this->store[ $group ][ $key ];
				}
				$found = false;
				return false;
			}
			public function get_multiple( $keys, $group = '', $force = false ) {
				$results = [];
				foreach ( $keys as $key ) {
					$found           = null;
					$results[ $key ] = $this->get( $key, $group, $force, $found );
				}
				return $results;
			}
			public function set( $key, $data, $group = '', $expire = 0 ) {
				if ( $this->fail_sets ) {
					return false;
				}
				$this->store[ $group ][ $key ] = $data;
				return true;
			}
			public function add( $key, $data, $group = '', $expire = 0 ) {
				if ( isset( $this->store[ $group ][ $key ] ) ) {
					return false;
				}
				return $this->set( $key, $data, $group, $expire );
			}
			public function replace( $key, $data, $group = '', $expire = 0 ) {
				if ( ! isset( $this->store[ $group ][ $key ] ) ) {
					return false;
				}
				return $this->set( $key, $data, $group, $expire );
			}
			public function set_multiple( array $data, $group = '', $expire = 0 ) {
				$results = [];
				foreach ( $data as $key => $value ) {
					$results[ $key ] = $this->set( $key, $value, $group, $expire );
				}
				return $results;
			}
			public function add_multiple( array $data, $group = '', $expire = 0 ) {
				$results = [];
				foreach ( $data as $key => $value ) {
					$results[ $key ] = $this->add( $key, $value, $group, $expire );
				}
				return $results;
			}
			public function delete( $key, $group = '' ) {
				$this->deleted[] = "$group/$key";
				unset( $this->store[ $group ][ $key ] );
				return true;
			}
		};
	}

	public function test_get_misses_on_cold_group_even_when_real_has_value(): void {
		$real = $this->fake_real_cache();
		$real->set( 'np_cached_block_abc_0', 'stale-html', 'newspack_blocks' );

		$cold  = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );
		$found = null;

		$this->assertFalse( $cold->get( 'np_cached_block_abc_0', 'newspack_blocks', false, $found ) );
		$this->assertFalse( $found, 'cold-group read must report not-found so callers treat it as a miss' );
	}

	public function test_get_misses_on_a_prefixed_cold_group(): void {
		// Newspack's block cache splits into per-page / feed group variants
		// (`newspack_blocks-post-{ID}`, `newspack_blocks-feed`); a static-Page
		// homepage uses `newspack_blocks-post-{ID}`. Cooling the base group must
		// cool those derived groups too, or the homepage keeps hitting the cache.
		$real = $this->fake_real_cache();
		$real->set( 'np_cached_block_abc_0', 'stale-html', 'newspack_blocks-post-42' );

		$cold  = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );
		$found = null;

		$this->assertFalse( $cold->get( 'np_cached_block_abc_0', 'newspack_blocks-post-42', false, $found ) );
		$this->assertFalse( $found );
	}

	public function test_get_does_not_cool_a_group_lacking_the_separator(): void {
		// Prefix match requires the `-` separator, so an unrelated group that
		// merely starts with a cold name still passes through.
		$real = $this->fake_real_cache();
		$real->set( 'k', 'warm', 'newspack_blocksx' );

		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$this->assertSame( 'warm', $cold->get( 'k', 'newspack_blocksx' ) );
	}

	public function test_get_multiple_misses_on_a_prefixed_cold_group(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$result = $cold->get_multiple( [ 'k1', 'k2' ], 'newspack_blocks-feed' );

		$this->assertSame( [ 'k1' => false, 'k2' => false ], $result );
	}

	public function test_get_passes_through_on_warm_group(): void {
		$real = $this->fake_real_cache();
		$real->set( 'alloptions', [ 'a' => 1 ], 'options' );

		$cold  = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );
		$found = null;

		$this->assertSame( [ 'a' => 1 ], $cold->get( 'alloptions', 'options', false, $found ) );
		$this->assertTrue( $found, 'warm-group read must delegate to the real cache untouched' );
	}

	public function test_set_writes_through_to_real_cache(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$cold->set( 'np_cached_block_abc_0', 'fresh-html', 'newspack_blocks', 120 );

		$this->assertSame( 'fresh-html', $real->store['newspack_blocks']['np_cached_block_abc_0'] );
	}

	public function test_get_reads_successful_set_on_cold_group(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$cold->set( 'k', 'fresh-html', 'newspack_blocks' );
		$found = null;

		$this->assertSame( 'fresh-html', $cold->get( 'k', 'newspack_blocks', false, $found ) );
		$this->assertTrue( $found );
	}

	public function test_failed_set_keeps_cold_group_read_cold(): void {
		$real = $this->fake_real_cache();
		$real->set( 'k', 'stale-html', 'newspack_blocks' );
		$real->fail_sets = true;

		$cold  = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );
		$found = null;

		$this->assertFalse( $cold->set( 'k', 'fresh-html', 'newspack_blocks' ) );
		$this->assertFalse( $cold->get( 'k', 'newspack_blocks', false, $found ) );
		$this->assertFalse( $found );
	}

	public function test_get_reads_successful_add_on_cold_group(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$this->assertTrue( $cold->add( 'k', 'fresh-html', 'newspack_blocks' ) );

		$this->assertSame( 'fresh-html', $cold->get( 'k', 'newspack_blocks' ) );
	}

	public function test_failed_add_keeps_cold_group_read_cold(): void {
		$real = $this->fake_real_cache();
		$real->set( 'k', 'stale-html', 'newspack_blocks' );

		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$this->assertFalse( $cold->add( 'k', 'fresh-html', 'newspack_blocks' ) );
		$this->assertFalse( $cold->get( 'k', 'newspack_blocks' ) );
	}

	public function test_get_reads_successful_replace_on_cold_group(): void {
		$real = $this->fake_real_cache();
		$real->set( 'k', 'stale-html', 'newspack_blocks' );

		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$this->assertTrue( $cold->replace( 'k', 'fresh-html', 'newspack_blocks' ) );

		$this->assertSame( 'fresh-html', $cold->get( 'k', 'newspack_blocks' ) );
	}

	public function test_failed_replace_keeps_cold_group_read_cold(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$this->assertFalse( $cold->replace( 'k', 'fresh-html', 'newspack_blocks' ) );
		$this->assertFalse( $cold->get( 'k', 'newspack_blocks' ) );
	}

	public function test_set_multiple_warms_successful_keys_on_cold_group(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$result = $cold->set_multiple( [ 'k1' => 'fresh-one', 'k2' => 'fresh-two' ], 'newspack_blocks' );

		$this->assertSame( [ 'k1' => true, 'k2' => true ], $result );
		$this->assertSame( [ 'k1' => 'fresh-one', 'k2' => 'fresh-two' ], $cold->get_multiple( [ 'k1', 'k2' ], 'newspack_blocks' ) );
	}

	public function test_add_multiple_warms_only_successful_keys_on_cold_group(): void {
		$real = $this->fake_real_cache();
		$real->set( 'k1', 'stale-one', 'newspack_blocks' );

		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$result = $cold->add_multiple( [ 'k1' => 'fresh-one', 'k2' => 'fresh-two' ], 'newspack_blocks' );

		$this->assertSame( [ 'k1' => false, 'k2' => true ], $result );
		$this->assertSame( [ 'k1' => false, 'k2' => 'fresh-two' ], $cold->get_multiple( [ 'k1', 'k2' ], 'newspack_blocks' ) );
	}

	public function test_get_multiple_misses_on_cold_group(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$result = $cold->get_multiple( [ 'k1', 'k2' ], 'newspack_blocks' );

		$this->assertSame( [ 'k1' => false, 'k2' => false ], $result );
	}

	public function test_get_multiple_reads_warm_keys_on_cold_group_and_misses_cold_keys(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$cold->set( 'k1', 'fresh-html', 'newspack_blocks' );

		$result = $cold->get_multiple( [ 'k1', 'k2' ], 'newspack_blocks' );

		$this->assertSame( [ 'k1' => 'fresh-html', 'k2' => false ], $result );
	}

	public function test_unknown_methods_delegate_to_real_cache(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		// delete is not overridden — it must pass through via __call.
		$cold->delete( 'k', 'newspack_blocks' );

		$this->assertSame( [ 'newspack_blocks/k' ], $real->deleted );
	}

	public function test_property_reads_delegate_to_real_cache(): void {
		// WP core / drop-ins read $wp_object_cache->cache_hits, ->global_groups, etc.
		$real              = $this->fake_real_cache();
		$real->cache_hits  = 7;
		$real->global_groups = [ 'users' => true ];

		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$this->assertSame( 7, $cold->cache_hits );
		$this->assertSame( [ 'users' => true ], $cold->global_groups );
		$this->assertTrue( isset( $cold->cache_hits ) );
		$this->assertFalse( isset( $cold->nonexistent ) );
	}

	public function test_property_writes_delegate_to_real_cache(): void {
		$real = $this->fake_real_cache();
		$cold = new Cold_Read_Object_Cache( $real, [ 'newspack_blocks' ] );

		$cold->cache_hits = 9;

		$this->assertSame( 9, $real->cache_hits );
	}
}
