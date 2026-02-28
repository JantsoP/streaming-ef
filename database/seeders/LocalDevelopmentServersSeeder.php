<?php

namespace Database\Seeders;

use App\Enum\ServerStatusEnum;
use App\Enum\ServerTypeEnum;
use App\Models\Server;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class LocalDevelopmentServersSeeder extends Seeder
{
    /**
     * Run the database seeds for local development environment.
     * Creates an origin server and an edge server for local testing.
     */
    public function run(): void
    {
        // Only run in local environment
        if (!app()->isLocal()) {
            $this->command->info('Skipping local development servers seeder (not in local environment)');
            return;
        }

        $this->command->info('Creating local development servers...');

        // Derive hostname from APP_URL so VM/remote setups show the correct IP
        $appHost = parse_url(config('app.url'), PHP_URL_HOST) ?? 'localhost';

        // Create Origin Server (SRS)
        // Match on type only so re-seeding updates rather than duplicates the row
        $origin = Server::updateOrCreate(
            [
                'type' => ServerTypeEnum::ORIGIN,
            ],
            [
                'hostname' => $appHost,
                'hetzner_id' => null,
                'ip' => null, // No DNS creation needed
                'port' => 8080,
                'shared_secret' => Str::random(40),
                'status' => ServerStatusEnum::ACTIVE,
                'max_clients' => 0, // Origin doesn't handle clients directly
                'immutable' => true,
                'hls_path' => '/live',
                'viewer_count' => 0,
                'last_heartbeat' => now(),
                'health_status' => 'unknown',
            ]
        );

        $this->command->info("✓ Created Origin Server: {$origin->hostname} (ID: {$origin->id})");

        // Create edge server for direct browser access
        // Match on type+port only so re-seeding updates hostname if APP_URL changed
        $localEdge = Server::updateOrCreate(
            [
                'type' => ServerTypeEnum::EDGE,
                'port' => 8085,
            ],
            [
                'hostname' => $appHost,
                'hetzner_id' => null,
                'ip' => $appHost,
                'shared_secret' => Str::random(40),
                'status' => ServerStatusEnum::ACTIVE,
                'max_clients' => 100,
                'immutable' => true,
                'viewer_count' => 0,
                'last_heartbeat' => now(),
                'health_status' => 'unknown',
            ]
        );

        $this->command->info("✓ Created Edge Server: {$localEdge->hostname}:{$localEdge->port} (ID: {$localEdge->id})");

        $this->command->info('');
        $this->command->info('Local development servers created successfully!');
        $this->command->info('');
        $this->command->info('Server Configuration:');
        $this->command->table(
            ['Type', 'Hostname', 'Port', 'Status', 'Purpose'],
            [
                ['Origin', $origin->hostname, $origin->port, $origin->status->value, 'RTMP ingress & HLS generation'],
                ['Edge', "{$localEdge->hostname}:{$localEdge->port}", $localEdge->port, $localEdge->status->value, 'Browser access point / CDN'],
            ]
        );
        
        $this->command->info('');
        $this->command->info('To start streaming:');
        $this->command->info('1. Run: docker-compose up');
        $this->command->info('2. Configure OBS with RTMP URL: rtmp://localhost:1935/live');
        $this->command->info('3. Use stream key from your Source model');
        $this->command->info('4. Access HLS stream at: http://localhost:8085/live/{source_slug}_fhd/index.m3u8');
    }
}