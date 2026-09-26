<?php

namespace App\Services;

use RuntimeException;

class BusinessProfileService
{
    /**
     * Top-level sections the profile must define.
     *
     * @var list<string>
     */
    public const REQUIRED_SECTIONS = [
        'business',
        'services',
        'delivery',
        'payment',
        'warranty',
        'maintenance',
        'rental',
        'faq',
    ];

    public function __construct(private readonly ?string $path = null) {}

    /**
     * Load and validate the Business Profile.
     *
     * Reads the file on every call on purpose: this service runs inside
     * long-lived queue workers, where cached data could go stale after
     * the profile file is updated. The file is tiny, so re-reading it
     * per message is acceptable.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the file is missing, unreadable, or invalid.
     */
    public function load(): array
    {
        $path = $this->path ?? storage_path('app/private/business_profile.json');

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Business profile not found at [{$path}].");
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            throw new RuntimeException("Business profile at [{$path}] could not be read.");
        }

        try {
            $profile = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException("Business profile at [{$path}] contains invalid JSON: {$e->getMessage()}", 0, $e);
        }

        $this->validate($profile, $path);

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $profile
     *
     * @throws RuntimeException
     */
    private function validate(array $profile, string $path): void
    {
        foreach (self::REQUIRED_SECTIONS as $section) {
            if (! array_key_exists($section, $profile)) {
                throw new RuntimeException("Business profile at [{$path}] is missing required section [{$section}].");
            }
        }

        if (! is_array($profile['business']) || empty($profile['business']['name']) || ! is_string($profile['business']['name'])) {
            throw new RuntimeException("Business profile at [{$path}] must define a non-empty [business.name].");
        }

        if (! is_array($profile['faq'])) {
            throw new RuntimeException("Business profile at [{$path}] must define [faq] as a list.");
        }
    }
}
