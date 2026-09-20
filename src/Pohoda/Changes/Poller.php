<?php

declare(strict_types=1);

namespace Pohoda\Changes;

use Pohoda\Changes\Webhook\Dispatcher;

/**
 * Poll all enabled accounting units and fan-out webhooks.
 */
class Poller extends \Ease\Atom
{
    private AccountingUnit $units;

    private AgendaWatcher $watcher;

    private Dispatcher $dispatcher;

    private string $lockFile;

    public function __construct(
        ?AccountingUnit $units = null,
        ?AgendaWatcher $watcher = null,
        ?Dispatcher $dispatcher = null,
        ?string $lockFile = null,
    ) {
        $this->units = $units ?? new AccountingUnit();
        $this->watcher = $watcher ?? new AgendaWatcher();
        $this->dispatcher = $dispatcher ?? new Dispatcher();
        $this->lockFile = $lockFile ?? sys_get_temp_dir().'/pohoda-changes-poller.lock';
    }

    /**
     * @return int number of change events recorded
     */
    public function run(): int
    {
        $fh = fopen($this->lockFile, 'c+');

        if ($fh === false || !flock($fh, \LOCK_EX | \LOCK_NB)) {
            $this->addStatusMessage('Another poller is already running', 'warning');

            if (is_resource($fh)) {
                fclose($fh);
            }

            return 0;
        }

        $total = 0;

        try {
            foreach ($this->units->listEnabled() as $unit) {
                $agendas = AccountingUnit::agendasFor($unit);

                foreach ($agendas as $agenda) {
                    try {
                        $changes = $this->watcher->poll($unit, $agenda);

                        foreach ($changes as $change) {
                            $this->dispatcher->dispatch($change, $unit);
                            ++$total;
                        }
                    } catch (\Throwable $e) {
                        $this->addStatusMessage(
                            sprintf(
                                'Poll failed for ico=%s year=%s agenda=%s: %s',
                                $unit['ico'],
                                $unit['year'],
                                $agenda,
                                $e->getMessage(),
                            ),
                            'error',
                        );
                    }
                }
            }
        } finally {
            flock($fh, \LOCK_UN);
            fclose($fh);
        }

        $this->addStatusMessage(sprintf('Poll finished, %d change(s)', $total), 'success');

        return $total;
    }
}
