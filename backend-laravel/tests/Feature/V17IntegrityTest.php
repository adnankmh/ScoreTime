<?php

namespace Tests\Feature;

use App\Models\DataProviderSyncLog;
use App\Services\DesignStudioService;
use App\Services\ProviderQuotaService;
use App\Support\FootballStatus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class V17IntegrityTest extends TestCase
{
    public function test_provider_statuses_are_canonical(): void
    {
        $this->assertSame('live', FootballStatus::canonical('1H'));
        $this->assertSame('halftime', FootballStatus::canonical('HT'));
        $this->assertSame('finished', FootballStatus::canonical('PEN'));
        $this->assertSame('postponed', FootballStatus::canonical('PST'));
        $this->assertSame('scheduled', FootballStatus::canonical('NS'));
    }

    public function test_free_quota_bucket_stops_before_the_reserve_is_spent(): void
    {
        Cache::flush();
        config()->set('football.free_plan_mode', true);
        config()->set('football.free_daily_limit', 4);
        config()->set('football.free_daily_reserve', 1);
        config()->set('football.free_live_daily_cap', 2);

        $quota = app(ProviderQuotaService::class);
        $quota->beforeCall('api-football', 'live');
        $quota->beforeCall('api-football', 'live');
        $this->assertSame(2, $quota->state()['buckets']['live']['used']);

        $this->expectException(\RuntimeException::class);
        $quota->beforeCall('api-football', 'live');
    }

    public function test_design_cache_clear_never_erases_provider_quota(): void
    {
        Cache::put('scoretime:test:quota-sentinel', 17, 60);
        app(DesignStudioService::class)->clear();
        $this->assertSame(17, Cache::get('scoretime:test:quota-sentinel'));
    }

    public function test_batch_quota_preflight_respects_the_provider_reported_reserve(): void
    {
        Cache::flush();
        config()->set('football.free_plan_mode', true);
        config()->set('football.free_daily_limit', 100);
        config()->set('football.free_daily_reserve', 20);
        config()->set('football.free_detail_daily_cap', 10);

        $quota = app(ProviderQuotaService::class);
        $this->assertTrue($quota->canSpend('api-football', 'details', 3));
        $quota->syncHeaders('api-football', ['X-RateLimit-Requests-Remaining' => ['22']]);
        $this->assertFalse($quota->canSpend('api-football', 'details', 3));
        $this->assertTrue($quota->canSpend('api-football', 'details', 2));
    }

    public function test_all_six_web_locales_have_identical_keys(): void
    {
        $locales = ['en', 'ar', 'fr', 'es', 'de', 'tr'];
        $expected = array_keys(require lang_path('en/ui.php'));
        sort($expected);

        foreach ($locales as $locale) {
            $keys = array_keys(require lang_path($locale.'/ui.php'));
            sort($keys);
            $this->assertSame($expected, $keys, 'Translation keys differ for '.$locale);
        }
    }

    public function test_match_date_rejects_non_iso_input(): void
    {
        $this->getJson('/api/v1/matches?date=tomorrow')->assertUnprocessable();
    }

    public function test_public_data_status_omits_raw_provider_diagnostics(): void
    {
        $log = DataProviderSyncLog::create([
            'provider' => 'fixture',
            'resource' => 'matches',
            'status' => 'failed',
            'records' => 0,
            'duration_ms' => 32,
            'message' => 'Synthetic internal provider error',
            'meta' => ['credential' => 'test-only-private-value'],
            'started_at' => now(),
            'finished_at' => now(),
        ]);

        $syncs = $this->getJson('/api/v1/data-status')
            ->assertOk()
            ->json('data.last_syncs');
        $entry = collect($syncs)->firstWhere('id', $log->id);
        $this->assertNotNull($entry);
        $this->assertSame('failed', $entry['status']);
        $this->assertSame('matches', $entry['resource']);
        $this->assertArrayNotHasKey('message', $entry);
        $this->assertArrayNotHasKey('meta', $entry);
    }
}
