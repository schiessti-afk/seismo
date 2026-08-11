<?php

declare(strict_types=1);

namespace App\Services\Usgs;

final class IngestResult
{
    public function __construct(
        public readonly bool $successful,
        public readonly int $upserted,
        public readonly int $skipped,
    ) {}
}
