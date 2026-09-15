<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test registration works
     */
    public function test_user_can_register()
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123'
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'user' => ['id', 'name', 'email'],
            'token',
            'message'
        ]);
        
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com'
        ]);
    }

    /**
     * Test login works
     */
    public function test_user_can_login()
    {
        $user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password123')
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123'
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'success',
            'user',
            'token',
            'message'
        ]);
    }

    /**
     * Test protected endpoints block unauthenticated requests
     */
    public function test_protected_routes_block_unauthenticated()
    {
        $endpoints = [
            ['method' => 'get', 'url' => '/api/target-dbs'],
            ['method' => 'post', 'url' => '/api/target-db/connect'],
            ['method' => 'post', 'url' => '/api/chat/generate-sql'],
            ['method' => 'post', 'url' => '/api/chat/execute-sql']
        ];

        foreach ($endpoints as $ep) {
            $method = $ep['method'];
            $response = $this->json($method, $ep['url']);
            $response->assertStatus(401);
        }
    }
}
