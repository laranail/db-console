<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;
use Simtabi\Laranail\DBConsole\Webhooks\WebhookManager;

/**
 * Remove a webhook subscription and forget its signing secret.
 */
final class WebhookRemoveCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.webhook:remove {id}';

    protected $description = 'Remove a webhook subscription';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:webhook:remove'];

    public function handle(WebhookManager $webhooks): int
    {
        try {
            $webhooks->unsubscribe($this->arg('id'));
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->success('Webhook subscription removed.');

        return self::SUCCESS;
    }
}
