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

    // OME server configuration
    'ome_host' => env('STREAM_OME_HOST', 'localhost'),
    'ome_rtmp_port' => env('STREAM_OME_RTMP_PORT', 1935),
    'ome_srt_port' => env('STREAM_OME_SRT_PORT', 3333),
    'ome_hls_port' => env('STREAM_OME_HLS_PORT', 8000),
    'ome_dash_port' => env('STREAM_OME_DASH_PORT', 8081),
    'ome_llhls_port' => env('STREAM_OME_LLHLS_PORT', 8082),
    'ome_lldash_port' => env('STREAM_OME_LLDASH_PORT', 8083),
    'ome_api_port' => env('STREAM_OME_API_PORT', 9000),
    'ome_dvr_path' => env('STREAM_OME_DVR_PATH', '/dvr/recordings'),
    'ome_api_url' => env('STREAM_OME_API_URL', 'http://localhost:9000'),
    'ome_api_key' => env('STREAM_OME_API_KEY', ''),

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


    // System streamkey for internal operations (thumbnails, monitoring, etc.)
    'system_streamkey' => env('STREAM_SYSTEM_STREAMKEY', ''),

    // Local streaming server override configuration
    // When client IPs match these subnets, force use of the specified hostname
    'local_streaming_ipv4_subnet' => env('LOCAL_STREAMING_IPV4_SUBNET', ''),
    'local_streaming_ipv6_subnet' => env('LOCAL_STREAMING_IPV6_SUBNET', ''),
    'local_streaming_hostname' => env('LOCAL_STREAMING_HOSTNAME', ''),
];
