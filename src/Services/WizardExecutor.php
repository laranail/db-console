<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Simtabi\Laranail\DBConsole\Enums\OperationType;
use Simtabi\Laranail\DBConsole\Events\RollbackFailed as RollbackFailedEvent;
use Simtabi\Laranail\DBConsole\Events\RollbackPerformed;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Exceptions\ExceptionTranslator;
use Simtabi\Laranail\DBConsole\Exceptions\RollbackFailed;
use Simtabi\Laranail\DBConsole\Logging\DBConsoleLogger;
use Simtabi\Laranail\DBConsole\Services\Wizard\WizardStep;
use Throwable;

/**
 * Orchestrates multi-step operations with compensating rollback, because DDL
 * is not transactional in most engines (section 14). Each step runs forward
 * in order; if any step fails, the compensations of the already-completed
 * steps run in reverse, undoing only what this run created.
 *
 *   create db ─ok→ create account ─ok→ grant ─FAIL
 *        │              │               └ nothing to undo for a failed grant
 *        │              └───────────────── compensate: drop the account
 *        └──────────────────────────────── compensate: drop the db (if empty)
 *
 * If a compensation itself fails, that is escalated immediately as
 * RollbackFailed (critical + alert) — the server may be in a partial state a
 * human must inspect. The original failure is re-thrown once the compensations
 * that could run have run.
 */
final readonly class WizardExecutor
{
    public function __construct(
        private Dispatcher $events,
        private DBConsoleLogger $log,
    ) {}

    /**
     * Run the steps in order. On any failure, roll back the completed steps in
     * reverse and re-throw the (translated) original failure. A rollback that
     * itself fails throws RollbackFailed instead.
     *
     * @param  list<WizardStep>  $steps
     * @return list<mixed> the forward results, in order, on full success
     */
    public function execute(string $server, OperationType $operation, array $steps): array
    {
        /** @var list<WizardStep> $completed */
        $completed = [];
        $results = [];

        foreach ($steps as $step) {
            try {
                $results[] = ($step->forward)();
                $completed[] = $step;
            } catch (Throwable $e) {
                $failure = ExceptionTranslator::from($e, [
                    'server' => $server,
                    'operation' => $operation->value,
                    'step' => $step->label,
                ]);

                $this->rollback($server, $operation, $completed, $failure);

                throw $failure;
            }
        }

        return $results;
    }

    /**
     * Run the compensations of completed steps in reverse. A compensation
     * failure is escalated as RollbackFailed.
     *
     * @param  list<WizardStep>  $completed
     */
    private function rollback(string $server, OperationType $operation, array $completed, DBConsoleException $failure): void
    {
        foreach (array_reverse($completed) as $step) {
            if ($step->compensate === null) {
                continue;
            }

            try {
                ($step->compensate)();
            } catch (Throwable $rollbackError) {
                $escalated = RollbackFailed::whileCompensating($step->label, [
                    'server' => $server,
                    'operation' => $operation->value,
                    'original_code' => $failure->code()->value,
                ], $rollbackError);

                $this->log->failure($operation->value, $server, $escalated);
                $this->events->dispatch(new RollbackFailedEvent($server, $operation, [
                    'target' => $step->label,
                    'step' => $step->label,
                ]));

                throw $escalated;
            }
        }

        $this->events->dispatch(new RollbackPerformed($server, $operation, [
            'target' => $failure->code()->value,
            'steps' => count($completed),
        ]));
    }
}
