<?php

declare(strict_types=1);

namespace Pohoda\Changes;

/**
 * Resolve changed document IDs for one agenda of one accounting unit.
 */
interface ChangeIdSource
{
    /**
     * @param array<string, mixed> $unit
     *
     * @return list<array{id: int, changed_at: ?string}>
     */
    public function listChanged(array $unit, string $agenda, ?string $watermark): array;
}
