<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Defines the ProductApiTest class and its project responsibilities. */
class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies product index is public. */
    public function test_product_index_is_public(): void
    {
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta']);
    }
}
