<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Console\Commands\Concerns;

use Simtabi\Laranail\DBConsole\Enums\ExistingResourceKind;
use Simtabi\Laranail\DBConsole\Enums\ExistingResourcePolicy;
use Simtabi\Laranail\DBConsole\Services\ExistingResourceGuard;
use Simtabi\Laranail\DBConsole\Exceptions\ResourceAlreadyExists;
use Simtabi\Laranail\DBConsole\Services\Results\OperationResult;

/**
 * The CLI end of the existing-resource policy. --on-existing names the policy
 * outright. Without it, an interactive run that finds the resource already
 * there asks, naming the risk of each option; a run with --no-interaction
 * uses the configured policy (refuse unless changed).
 */
trait AsksAboutExistingResources
{
    /**
     * The --on-existing value: null when unset, false when it is not a policy.
     */
    protected function onExistingOption(): ExistingResourcePolicy|false|null
    {
        $value = $this->option('on-existing');
        if (! is_string($value) || $value === '') {
            return null;
        }

        return ExistingResourcePolicy::tryFrom($value) ?? false;
    }

    protected function invalidOnExisting(): int
    {
        $this->failure('--on-existing must be one of: ' . implode(', ', ExistingResourcePolicy::values()) . '.');

        return self::FAILURE;
    }

    /**
     * Ask what to do about $what, which is already on the server, offering the
     * options that make sense for $kind with their risks. The default is the
     * configured policy, so pressing return changes nothing about behaviour.
     */
    protected function askOnExisting(ExistingResourceKind $kind, string $what): ExistingResourcePolicy
    {
        $default = app(ExistingResourceGuard::class)->resolve($kind, $this->server());

        $choices = [];
        foreach (ExistingResourcePolicy::casesFor($kind) as $policy) {
            $choices[$policy->value] = (string) $policy->description();
        }

        $answer = $this->choice("{$what} already exists. What should happen to it?", $choices, $default->value);

        return ExistingResourcePolicy::tryFrom(is_string($answer) ? $answer : '') ?? ExistingResourcePolicy::Refuse;
    }

    protected function refused(ResourceAlreadyExists $e): int
    {
        $this->failure($e->userMessage());

        $options = $e->options();
        if ($options !== []) {
            $this->line('Re-run with --on-existing=' . implode(' or --on-existing=', $options) . ' to take it over.');
        }

        return self::FAILURE;
    }

    protected function reportAdoption(OperationResult $result, string $what): void
    {
        if (($result->data['password_applied'] ?? false) === true) {
            $this->components->warn("{$what} already existed: adopted, and its password replaced. Anything using the old password can no longer log in.");

            return;
        }

        $this->components->warn("{$what} already existed: adopted as it was, password unchanged.");
    }
}
