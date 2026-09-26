<?php

namespace Tests\Unit;

use App\Services\BusinessProfileService;
use RuntimeException;
use Tests\TestCase;

class BusinessProfileServiceTest extends TestCase
{
    public function test_loads_a_valid_profile(): void
    {
        $path = $this->writeTempProfile([
            'business' => ['name' => 'Test Business'],
            'services' => [],
            'delivery' => [],
            'payment' => [],
            'warranty' => [],
            'maintenance' => [],
            'rental' => [],
            'faq' => [],
        ]);

        $profile = (new BusinessProfileService($path))->load();

        $this->assertSame('Test Business', $profile['business']['name']);

        unlink($path);
    }

    public function test_throws_when_file_is_missing(): void
    {
        $this->expectException(RuntimeException::class);

        (new BusinessProfileService('/path/that/does/not/exist.json'))->load();
    }

    public function test_throws_for_invalid_json(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'profile_');
        file_put_contents($path, '{not valid json');

        try {
            (new BusinessProfileService($path))->load();

            $this->fail('Expected a RuntimeException for invalid JSON.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('invalid JSON', $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_throws_when_required_section_is_missing(): void
    {
        $path = $this->writeTempProfile([
            'business' => ['name' => 'Test Business'],
            // 'faq' intentionally missing
            'services' => [],
            'delivery' => [],
            'payment' => [],
            'warranty' => [],
            'maintenance' => [],
            'rental' => [],
        ]);

        try {
            (new BusinessProfileService($path))->load();

            $this->fail('Expected a RuntimeException for a missing section.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('faq', $e->getMessage());
        } finally {
            unlink($path);
        }
    }

    public function test_loads_the_shipped_business_profile(): void
    {
        $profile = (new BusinessProfileService)->load();

        foreach (BusinessProfileService::REQUIRED_SECTIONS as $section) {
            $this->assertArrayHasKey($section, $profile);
        }

        $this->assertSame('DR.SCENT', $profile['business']['name']);
        $this->assertNotEmpty($profile['faq']);
    }

    public function test_shipped_profile_contains_no_unconfirmed_claims(): void
    {
        $profile = (new BusinessProfileService)->load();

        $raw = json_encode($profile, JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsStringIgnoringCase('alexa', $raw);
        $this->assertStringNotContainsString('Wi-Fi', $raw);
        $this->assertStringNotContainsString('تطبيق', $raw);

        foreach (['maintenance_included', 'replacement_on_failure_included', 'deposit', 'minimum_duration', 'cancellation'] as $key) {
            $this->assertArrayNotHasKey($key, $profile['rental']);
        }

        $this->assertSame([], $profile['maintenance']);
        $this->assertSame([], $profile['delivery']);
        $this->assertSame([], $profile['payment']);
        $this->assertSame([], $profile['warranty']);

        $this->assertSame('965365486', $profile['business']['phone']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeTempProfile(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'profile_');
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE));

        return $path;
    }
}
