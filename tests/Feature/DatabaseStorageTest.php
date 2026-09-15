<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\TargetDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class DatabaseStorageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test target database password encryption/decryption
     */
    public function test_target_db_password_is_encrypted_automatically()
    {
        $user = User::create([
            'name' => 'User A',
            'email' => 'usera@example.com',
            'password' => bcrypt('password123')
        ]);

        $db = TargetDatabase::create([
            'user_id' => $user->id,
            'name' => 'Test Database',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'test_db',
            'username' => 'root',
            'password' => 'secret_password_123'
        ]);

        // Assert database holds the encrypted password, not the raw one
        $this->assertDatabaseMissing('target_databases', [
            'password' => 'secret_password_123'
        ]);

        // Assert we can decrypt it from the model
        $freshDb = TargetDatabase::find($db->id);
        $this->assertEquals('secret_password_123', $freshDb->password);
    }

    /**
     * Test isolation: User A cannot see or delete User B's databases
     */
    public function test_user_database_isolation()
    {
        $userA = User::create([
            'name' => 'User A',
            'email' => 'usera@example.com',
            'password' => bcrypt('password123')
        ]);

        $userB = User::create([
            'name' => 'User B',
            'email' => 'userb@example.com',
            'password' => bcrypt('password123')
        ]);

        // User A database
        $dbA = TargetDatabase::create([
            'user_id' => $userA->id,
            'name' => 'Database A',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'db_a',
            'username' => 'root',
            'password' => 'secret'
        ]);

        // User B database
        $dbB = TargetDatabase::create([
            'user_id' => $userB->id,
            'name' => 'Database B',
            'host' => '127.0.0.1',
            'port' => '3306',
            'database' => 'db_b',
            'username' => 'root',
            'password' => 'secret'
        ]);

        // Act as User A to fetch databases
        $response = $this->actingAs($userA, 'sanctum')->getJson('/api/target-dbs');
        $response->assertStatus(200);
        $response->assertJsonFragment(['name' => 'Database A']);
        $response->assertJsonMissing(['name' => 'Database B']);

        // Try to connect to User B's database as User A
        $responseConnect = $this->actingAs($userA, 'sanctum')->postJson('/api/chat/execute-sql', [
            'sql_query' => 'SELECT 1',
            'database_id' => $dbB->id
        ]);
        $responseConnect->assertStatus(404); // ModelNotFoundException handled as 404

        // Try to delete User B's database as User A
        $responseDelete = $this->actingAs($userA, 'sanctum')->deleteJson('/api/target-dbs/' . $dbB->id);
        $responseDelete->assertStatus(404);
    }
}
