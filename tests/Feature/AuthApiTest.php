<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Defines the AuthApiTest class and its project responsibilities. */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    /** Verifies customer can register. */
    public function test_customer_can_register(): void
    {
        $response = $this->withHeader('Origin', 'http://localhost:8000')
            ->postJson('/api/v1/auth/register', [
            'name' => 'Test Customer',
            'email' => 'customer@example.com',
            'password' => 'StrongPass123',
            'password_confirmation' => 'StrongPass123',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('data.email', 'customer@example.com');

        $this->assertAuthenticated();
    }
}
