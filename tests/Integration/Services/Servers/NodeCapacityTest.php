<?php

namespace App\Tests\Integration\Services\Servers;

use App\Models\Node;
use App\Tests\Integration\IntegrationTestCase;
use Illuminate\Validation\ValidationException;

/**
 * LoomNode: a server create or resize can't put a node past its memory, disk or CPU limit.
 */
class NodeCapacityTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('panel.enforce_node_capacity', true);
    }

    public function test_create_that_fits_is_allowed(): void
    {
        $node = Node::factory()->create(['memory' => 1024, 'disk' => 10240, 'cpu' => 100]);

        $server = $this->createServerModel(['node_id' => $node->id, 'memory' => 512, 'disk' => 512, 'cpu' => 50]);

        $this->assertSame(512, $server->memory);
    }

    public function test_create_past_the_node_limit_is_refused(): void
    {
        $node = Node::factory()->create(['memory' => 1024, 'disk' => 10240, 'cpu' => 100]);
        $this->createServerModel(['node_id' => $node->id, 'memory' => 768, 'disk' => 512, 'cpu' => 50]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('can give this server at most 256 MiB of memory');

        $this->createServerModel(['node_id' => $node->id, 'memory' => 512, 'disk' => 512, 'cpu' => 50]);
    }

    public function test_unlimited_server_on_a_limited_node_is_refused(): void
    {
        $node = Node::factory()->create(['memory' => 1024, 'disk' => 10240, 'cpu' => 100]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('needs a cpu limit');

        $this->createServerModel(['node_id' => $node->id, 'memory' => 512, 'disk' => 512, 'cpu' => 0]);
    }

    public function test_growing_past_the_limit_is_refused_and_shrinking_is_allowed(): void
    {
        $node = Node::factory()->create(['memory' => 1024, 'disk' => 10240, 'cpu' => 100]);
        $server = $this->createServerModel(['node_id' => $node->id, 'memory' => 512, 'disk' => 512, 'cpu' => 50]);

        $server->update(['memory' => 256]);
        $this->assertSame(256, $server->refresh()->memory);

        $this->expectException(ValidationException::class);
        $server->update(['memory' => 2048]);
    }

    public function test_the_check_can_be_switched_off(): void
    {
        config()->set('panel.enforce_node_capacity', false);
        $node = Node::factory()->create(['memory' => 1024, 'disk' => 10240, 'cpu' => 100]);

        $server = $this->createServerModel(['node_id' => $node->id, 'memory' => 4096, 'disk' => 512, 'cpu' => 0]);

        $this->assertSame(4096, $server->memory);
    }
}
