<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class OmeApiService
{
    protected $baseUrl;
    protected $apiKey;

    public function __construct()
    {
        $this->baseUrl = config('stream.ome_api_url');
        $this->apiKey = config('stream.ome_api_key');
    }

    protected function headers()
    {
        return [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
        ];
    }

    public function listStreams()
    {
        return Http::withHeaders($this->headers())
            ->get($this->baseUrl . '/v1/streams')
            ->json();
    }

    public function createStream(array $data)
    {
        return Http::withHeaders($this->headers())
            ->post($this->baseUrl . '/v1/streams', $data)
            ->json();
    }

    public function deleteStream(string $id)
    {
        return Http::withHeaders($this->headers())
            ->delete($this->baseUrl . "/v1/streams/{$id}")
            ->json();
    }
}
