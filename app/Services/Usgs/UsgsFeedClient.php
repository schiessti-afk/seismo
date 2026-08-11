<?php

declare(strict_types=1);

namespace App\Services\Usgs;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class UsgsFeedClient
{
    /**
     * @return array<string, mixed>|null
     */
    public function fetch(string $url, int $timeoutSeconds = 30): ?array
    {
        try {
            $response = Http::timeout($timeoutSeconds)
                ->acceptJson()
                ->get($url);

            if (! $response->successful()) {
                Log::warning('USGS feed request failed', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);

                return null;
            }

            /** @var array<string, mixed>|null $payload */
            $payload = $response->json();

            if (! is_array($payload)) {
                Log::warning('USGS feed returned invalid JSON', ['url' => $url]);

                return null;
            }

            return $payload;
        } catch (\Throwable $exception) {
            Log::warning('USGS feed request error', [
                'url' => $url,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
