<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Webhooks\WebhookManager;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Add a webhook subscription; prints its signing secret once.
 */
final class WebhookAddCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.webhook:add {--url=} {--events=*} {--server=}';

    protected $description = 'Add a webhook subscription (prints the signing secret once)';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:webhook:add'];

    public function handle(WebhookManager $webhooks): int
    {
        $url = $this->opt('url');
        /** @var list<string> $events */
        $events = (array) $this->option('events');

        if ($url === '' || $events === []) {
            $this->failure('--url and at least one --events are required.');

            return self::FAILURE;
        }

        try {
            $server = $this->opt('server');
            [$subscription, $secret] = $webhooks->subscribe($url, $events, $server !== '' ? $server : null);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->success("Webhook {$subscription->id} added.");
        $this->components->warn('Signing secret (shown once): ' . $secret);

        return self::SUCCESS;
    }
}
