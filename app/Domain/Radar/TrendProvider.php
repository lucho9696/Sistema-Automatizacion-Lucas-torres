<?php

namespace App\Domain\Radar;

interface TrendProvider
{
    public function name(): string;

    /** live | demo */
    public function mode(): string;

    /**
     * @return array<int, array{keyword: string, url: ?string, position: int, trend_type: string}>
     */
    public function fetch(string $site, ?string $categoryId = null): array;
}
