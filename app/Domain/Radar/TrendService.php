<?php

namespace App\Domain\Radar;

use App\Domain\Integrations\SyncRunRecorder;
use App\Domain\Shared\Auditor;
use App\Models\SyncRun;
use App\Models\TrendCandidate;
use App\Models\TrendSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TrendService
{
    public function __construct(
        private CandidateScorer $scorer,
        private SyncRunRecorder $runs,
        private Auditor $auditor,
    ) {}

    public static function provider(): TrendProvider
    {
        return match (config('integrations.trends.provider')) {
            'mercadolibre' => app(MercadoLibreTrendsProvider::class),
            default => app(DemoTrendProvider::class),
        };
    }

    public static function normalizeKey(string $keyword): string
    {
        $k = Str::of(Str::ascii(preg_replace('/^\[DEMO\]\s*/', '', $keyword)))->lower()->replaceMatches('/[^a-z0-9 ]/', ' ')->squish()->toString();

        return (str_starts_with($keyword, '[DEMO]') ? 'demo:' : '').$k;
    }

    /** Consulta la fuente, guarda la instantánea con fuente y fecha, agrupa y recalcula puntajes. */
    public function sync(?string $categoryId = null, ?TrendProvider $provider = null): SyncRun
    {
        $provider ??= self::provider();
        $site = config('integrations.trends.site');
        $params = ['site' => $site, 'category_id' => $categoryId];
        $runId = null;

        try {
            $this->runs->run($provider->name(), $provider->mode(), $params, function (SyncRun $run) use ($provider, $site, $categoryId, &$runId) {
                $runId = $run->id;
                $items = $provider->fetch($site, $categoryId);
                $run->request_count = 1;
                $run->items_count = count($items);
                $isDemo = $provider->mode() === 'demo';
                DB::transaction(function () use ($items, $run, $site, $categoryId, $isDemo, $provider) {
                    foreach ($items as $item) {
                        $this->record([
                            'source' => $provider->name(), 'site' => $site, 'category_id' => $categoryId,
                            'keyword' => $item['keyword'], 'url' => $item['url'], 'position' => $item['position'],
                            'trend_type' => $item['trend_type'], 'is_demo' => $isDemo, 'sync_run_id' => $run->id,
                        ]);
                    }
                });
            });
        } catch (\Throwable) {
            // El fallo queda registrado en sync_runs con su mensaje sanitizado.
        }

        return SyncRun::findOrFail($runId);
    }

    /** Señal cargada a mano (p. ej. observación en TikTok o en un marketplace), con su fuente. */
    public function recordManual(string $keyword, string $source, ?string $url, ?string $fetchedAt = null): TrendSnapshot
    {
        return DB::transaction(fn () => $this->record([
            'source' => $source, 'keyword' => $keyword, 'url' => $url, 'position' => null,
            'trend_type' => 'manual', 'is_demo' => false, 'fetched_at' => $fetchedAt ?? now(),
        ]));
    }

    private function record(array $attrs): TrendSnapshot
    {
        $key = self::normalizeKey($attrs['keyword']);
        $candidate = TrendCandidate::firstOrCreate(
            ['normalized_key' => $key],
            ['name' => preg_replace('/^\[DEMO\]\s*/', '', $attrs['keyword']), 'is_demo' => $attrs['is_demo']]
        );
        $snapshot = TrendSnapshot::create($attrs + ['trend_candidate_id' => $candidate->id, 'fetched_at' => $attrs['fetched_at'] ?? now()]);
        $candidate->update(['last_seen_at' => $snapshot->fetched_at]);
        $this->scorer->apply($candidate);

        return $snapshot;
    }

    /** @return array<int, array{label: string, url: string}> */
    public static function supplierLinks(TrendCandidate $c): array
    {
        $q = rawurlencode($c->name);
        $links = [];
        foreach (config('integrations.supplier_search_links') as $label => $template) {
            $links[] = ['label' => $label, 'url' => str_replace('{q}', $q, $template)];
        }

        return $links;
    }
}
