<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Models\WebhookSubscription;

/**
 * List webhook subscriptions.
 */
final class WebhookListCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.webhook:list';

    protected $description = 'List webhook subscriptions';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:webhook:list'];

    public function handle(): int
    {
        foreach (WebhookSubscription::query()->get() as $s) {
            $state = $s->active ? 'active' : 'disabled';
            $this->line("{$s->id}  [{$state}]  {$s->url}");
            $this->line('  events: ' . implode(', ', $s->events) . ($s->server !== null ? "  · server: {$s->server}" : ''));
        }

        return self::SUCCESS;
    }
}
