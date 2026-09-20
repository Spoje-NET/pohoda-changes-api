<?php

declare(strict_types=1);

namespace Pohoda\Changes\Webhook;

/**
 * Persist outbound webhook delivery attempts.
 */
class DeliveryLog extends \Ease\SQL\Engine
{
    public string $myTable = 'webhook_deliveries';

    public string $keyColumn = 'id';

    /**
     * @param array<string, mixed> $row
     */
    public function write(array $row): void
    {
        $this->insertToSQL($row);
    }
}
