<?php
declare(strict_types=1);

namespace Newspack_Cache_Cozy\Tests;

use Newspack_Cache_Cozy\Cache_Cozy_Tick_Node;
use Newspack_Nodes\Tests\TestCase;

/**
 * Every node_schema constructor argument must ship a description — it becomes the
 * argument's tooltip in the topology console, so a blank one is a blank tooltip.
 */
final class NodeSchemaArgumentDescriptionsTest extends TestCase {
	public function test_every_node_schema_argument_has_a_description(): void {
		$missing = [];
		foreach ( Cache_Cozy_Tick_Node::node_schema()['arguments'] ?? [] as $arg ) {
			$desc = $arg['description'] ?? '';
			if ( ! \is_string( $desc ) || '' === \trim( $desc ) ) {
				$missing[] = (string) ( $arg['name'] ?? '?' );
			}
		}
		$this->assertSame(
			[],
			$missing,
			'Cache_Cozy_Tick args missing a description: ' . \implode( ', ', $missing )
		);
	}
}
