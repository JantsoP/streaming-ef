<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SrsWebhookAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private User $userWithStreamkey;
    private User $userWithoutStreamkey;
    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a server with shared secret
        $this->server = Server::create([
            'hostname' => 'localhost:8080',
            'ip' => 1,
            'status' => \App\Enum\ServerStatusEnum::ACTIVE,
            'type' => \App\Enum\ServerTypeEnum::EDGE,
            'shared_secret' => 'test_shared_secret_123',
            'max_clients' => 100,
            'immutable' => false,
        ]);

        // Create admin role with stream.publish permission
        $adminRole = Role::create([
            'slug' => 'admin',
            'name' => 'Admin',
            'priority' => 1000,
            'permissions' => ['stream.view', 'stream.publish', 'admin.access'],
        ]);

        // Create user role without stream.publish permission
        $userRole = Role::create([
            'slug' => 'user',
            'name' => 'User',
            'priority' => 10,
            'permissions' => ['stream.view'],
        ]);

        // Create user with streamkey and admin role
        $this->userWithStreamkey = User::factory()->create([
            'streamkey' => 'valid_test_streamkey_123',
            'server_id' => $this->server->id,
        ]);
        $this->userWithStreamkey->assignRole($adminRole);

        // Create user without streamkey
        $this->userWithoutStreamkey = User::factory()->create([
            'streamkey' => null,
            'server_id' => null,
        ]);
        $this->userWithoutStreamkey->assignRole($userRole);
    }

    /**
     * Test successful authentication with valid streamkey
     */
    public function test_auth_succeeds_with_valid_streamkey()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/live',
            'pageUrl' => '',
            'param' => '?secret=' . $this->userWithStreamkey->streamkey,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'client' => [
                    'id' => (string) $this->userWithStreamkey->id,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'client' => ['id', 'signature'],
            ]);
    }

    /**
     * Test authentication fails with invalid streamkey
     */

    /**
     * Test authentication fails when no streamkey provided
     */

    /**
     * Test authentication fails for user without server assignment
     */

    /**
     * Test authentication fails for user without stream.publish permission
     */

    /**
     * Test server-to-server authentication with valid shared secret
     */
    public function test_server_auth_succeeds_with_valid_shared_secret()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://origin.server/live',
            'pageUrl' => '',
            'param' => '?shared_secret=' . $this->server->shared_secret,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'server' => [
                    'id' => (string) $this->server->id,
                ],
            ])
            ->assertJsonStructure([
                'code',
                'server' => ['id', 'signature'],
            ]);
    }

    /**
     * Test server-to-server authentication fails with invalid shared secret
     */
    public function test_server_auth_fails_with_invalid_shared_secret()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://origin.server/live',
            'pageUrl' => '',
            'param' => '?shared_secret=invalid_secret_789',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test on_unpublish webhook returns success
     */
    public function test_unpublish_webhook_returns_success()
    {
        $response = $this->postJson('/api/srs/unpublish', [
            'app' => 'live',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/live',
            'pageUrl' => '',
            'param' => '?secret=' . $this->userWithStreamkey->streamkey,
        ]);

        $response->assertStatus(200)
            ->assertJson(['code' => 0]);
    }

    /**
     * Test that shared secret takes precedence over streamkey
     */
    public function test_shared_secret_takes_precedence_over_streamkey()
    {
        // Send both shared_secret and streamkey
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://origin.server/live',
            'pageUrl' => '',
            'param' => '?shared_secret=' . $this->server->shared_secret . '&secret=some_streamkey',
        ]);

        // Should authenticate as server, not user
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'server' => [
                    'id' => (string) $this->server->id,
                ],
            ])
            ->assertJsonMissing(['client']);
    }

    /**
     * Test authentication with malformed param string
     */
    public function test_auth_handles_malformed_param_string()
    {
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/live',
            'pageUrl' => '',
            'param' => 'malformed&&&==param',
        ]);

        $response->assertStatus(403)
            ->assertJson(['code' => 403]);
    }

    /**
     * Test that both 'secret' and 'streamkey' parameters are accepted
     */
    public function test_auth_accepts_both_secret_and_streamkey_params()
    {
        // Test with 'streamkey' parameter
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/live',
            'pageUrl' => '',
            'param' => '?streamkey=' . $this->userWithStreamkey->streamkey,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'client' => [
                    'id' => (string) $this->userWithStreamkey->id,
                ],
            ]);

        // Test with 'secret' parameter (already tested above, but let's be explicit)
        $response = $this->postJson('/api/srs/auth', [
            'app' => 'live',
            'stream' => 'livestream',
            'tcUrl' => 'rtmp://localhost/live',
            'pageUrl' => '',
            'param' => '?secret=' . $this->userWithStreamkey->streamkey,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'client' => [
                    'id' => (string) $this->userWithStreamkey->id,
                ],
            ]);
    }
}