<?php

namespace Ayangzy\RealSeed\Planning;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;

/**
 * Caches AI suggestions as reviewable JSON files so repeated runs with the same inputs
 * make no AI call and, together with --seed, produce identical data. The file also
 * pins the timeline anchor, so the same plan generates the same dates on later days.
 *
 * Cached files are re-validated on every use: an edited file is as untrusted as a
 * fresh AI response.
 */
final class PlanStore
{
    private const VERSION = 1;

    public function __construct(
        private readonly Filesystem $files,
        private readonly string $path,
    ) {
    }

    /**
     * @param  array<string, mixed>  $inputs  Everything that should produce a different plan when changed.
     */
    public function key(array $inputs): string
    {
        ksort($inputs);

        return hash('xxh128', json_encode($inputs));
    }

    /**
     * @return array{anchor: CarbonImmutable, provider: string, domain: ?string, suggestions: array}|null
     */
    public function get(string $key): ?array
    {
        $file = $this->file($key);

        if (! $this->files->exists($file)) {
            return null;
        }

        $data = json_decode($this->files->get($file), true);

        if (! is_array($data) || ($data['version'] ?? null) !== self::VERSION || ! is_array($data['suggestions'] ?? null)) {
            return null;
        }

        return [
            'anchor' => CarbonImmutable::parse($data['anchor']),
            'provider' => (string) ($data['provider'] ?? ''),
            'domain' => $data['domain'] ?? null,
            'suggestions' => $data['suggestions'],
        ];
    }

    public function put(string $key, CarbonImmutable $anchor, string $provider, ?string $scenario, array $suggestions): string
    {
        $this->files->ensureDirectoryExists($this->path);

        $this->files->put($this->file($key), json_encode([
            'version' => self::VERSION,
            'created_at' => CarbonImmutable::now()->toIso8601String(),
            'anchor' => $anchor->toIso8601String(),
            'provider' => $provider,
            'scenario' => $scenario,
            'domain' => $suggestions['domain'] ?? null,
            'suggestions' => $suggestions,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $this->file($key);
    }

    public function file(string $key): string
    {
        return rtrim($this->path, '/')."/{$key}.json";
    }
}
