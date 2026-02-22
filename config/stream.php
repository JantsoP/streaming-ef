<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Streaming Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the streaming infrastructure including RTMP, HLS,
    | and server provisioning settings.
    |
    */

    // RTMP server configuration
    'rtmp_host' => env('STREAM_RTMP_HOST', 'localhost:1935'),
    'rtmp_port' => env('STREAM_RTMP_PORT', 1935),

    // Session validation
    'validate_session_ip' => env('STREAM_VALIDATE_SESSION_IP', false),
    'session_timeout' => env('STREAM_SESSION_TIMEOUT', 60), // seconds

    // HLS tracker API key
    'hls_tracker_api_key' => env('STREAM_HLS_TRACKER_API_KEY', ''),

    // Server provisioning
    'server' => [
        'origin' => [
            'type' => 'ccx33',
            'max_streams' => 10,
        ],
        'edge' => [
            'type' => 'cx22',
            'max_clients' => 100,
        ],
    ],

    // Auto-scaling thresholds
    'autoscale' => [
        'enabled' => env('STREAM_AUTOSCALE_ENABLED', false),
        'min_servers' => env('STREAM_AUTOSCALE_MIN_SERVERS', 1),
        'max_servers' => env('STREAM_AUTOSCALE_MAX_SERVERS', 10),
        'scale_up_threshold' => env('STREAM_AUTOSCALE_UP_THRESHOLD', 80), // % capacity
        'scale_down_threshold' => env('STREAM_AUTOSCALE_DOWN_THRESHOLD', 20), // % capacity
        'cooldown_minutes' => env('STREAM_AUTOSCALE_COOLDOWN', 5),
    ],

    // DVR / VOD processing settings
    'dvr' => [
        // Event slug used in the S3 path: {vod_base_path}/{event_slug}/{show_slug}/
        'event_slug'    => env('DVR_EVENT_SLUG', 'event'),
        // S3 base path prefix for VOD recordings (mirrors S3_BASE_PATH in dvr-process.sh)
        'vod_base_path' => env('DVR_VOD_BASE_PATH', 'on-demand'),
        // Minutes to wait after ShowEnded before processing (only used by legacy DVR pipeline)
        'processing_delay_minutes' => (int) env('DVR_PROCESSING_DELAY_MINUTES', 12),
    ],

    // Live-archive VOD settings
    // The archive FFmpeg writes all HLS segments to this directory during the stream.
    // On show end, CreateVodFromShowJob uploads them to S3, verifies, then deletes.
    'vod' => [
        // Must be the same Docker volume path accessible from both ffmpeg-hls and laravel.test
        'archive_base_dir' => env('HLS_ARCHIVE_BASE_DIR', '/var/www/hls/archive'),
        // Flag files directory: Laravel touches {slug} here on Go Live, removes it on End Stream.
        // stream-manager.sh polls this dir to start/stop archive FFmpeg per-show.
        'archive_flags_dir' => env('HLS_ARCHIVE_FLAGS_DIR', '/var/www/hls/archive-flags'),        // Pause flag files directory: admin touches {source_slug} here to pause recording during intermissions.
        // stream-manager.sh stops archive FFmpeg while this flag exists; removes on resume and appends from where it left off.
        'archive_pause_dir' => env('HLS_ARCHIVE_PAUSE_DIR', '/var/www/hls/archive-pause'),        // Seconds to wait after ShowEnded before dispatching the VOD upload job.
        // This gives archive FFmpeg time to write #EXT-X-ENDLIST after receiving SIGTERM.
        // The job also validates #EXT-X-ENDLIST presence and retries if missing.
        'delay_seconds' => (int) env('VOD_CREATION_DELAY_SECONDS', 15),
    ],

    // Stream quality settings (bitrates in kbps)
    'qualities' => [
        'fhd' => [
            'resolution' => '1920x1080',
            'video_bitrate' => 6000,
            'audio_bitrate' => 192,
            'fps' => 30,
        ],
        'hd' => [
            'resolution' => '1280x720',
            'video_bitrate' => 3000,
            'audio_bitrate' => 160,
            'fps' => 30,
        ],
        'sd' => [
            'resolution' => '854x480',
            'video_bitrate' => 1500,
            'audio_bitrate' => 128,
            'fps' => 30,
        ],
    ],

    // Docker internal networking configuration
    'docker' => [
        'hls_host' => env('DOCKER_HLS_HOST', 'edge'),
        'hls_port' => env('DOCKER_HLS_PORT', 80),
    ],

    // System streamkey for internal operations (thumbnails, monitoring, etc.)
    'system_streamkey' => env('STREAM_SYSTEM_STREAMKEY', ''),

    // Local streaming server override configuration
    // When client IPs match these subnets, force use of the specified hostname
    'local_streaming_ipv4_subnet' => env('LOCAL_STREAMING_IPV4_SUBNET', ''),
    'local_streaming_ipv6_subnet' => env('LOCAL_STREAMING_IPV6_SUBNET', ''),
    'local_streaming_hostname' => env('LOCAL_STREAMING_HOSTNAME', ''),
];
