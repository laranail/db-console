<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Simtabi\Laranail\DBConsole\Api\TokenManager;
use Simtabi\Laranail\DBConsole\Exceptions\DBConsoleException;

/**
 * Issue an API token whose abilities never exceed the operator's own.
 */
final class TokenIssueCommand extends DBConsoleCommand
{
    protected $signature = 'laranail::db-console.token:issue {--user=} {--name=api} {--abilities=*}';

    protected $description = 'Issue an API token (abilities cannot exceed the operator\'s own permissions)';

    /** @var list<string> */
    protected array $commandAliases = ['db-console:token:issue'];

    public function handle(TokenManager $tokens): int
    {
        $operator = $this->resolveUser($this->opt('user'));
        if (! $operator instanceof Authenticatable) {
            $this->failure('Could not resolve --user.');

            return self::FAILURE;
        }

        try {
            /** @var list<string> $abilities */
            $abilities = (array) $this->option('abilities');
            $issued = $tokens->issue($operator, $this->opt('name') ?: 'api', $abilities);
        } catch (DBConsoleException $e) {
            $this->failure($e->userMessage());

            return self::FAILURE;
        }

        $this->success('Token issued with abilities: ' . implode(', ', $issued['abilities']));
        $this->line('');
        $this->components->warn('Token (shown once — store it now): ' . $issued['token']);

        return self::SUCCESS;
    }

    private function resolveUser(string $id): ?Authenticatable
    {
        /** @var class-string<Model> $model */
        $model = (string) $this->config()->get('laranail.db-console.rbac.user_model', '\App\Models\User');
        if ($id === '' || ! class_exists($model)) {
            return null;
        }

        $user = $model::query()->find($id);

        return $user instanceof Authenticatable ? $user : null;
    }
}
