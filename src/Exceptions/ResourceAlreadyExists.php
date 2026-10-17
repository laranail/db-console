<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Exceptions;

use Override;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * A create found the account or database already there, and the existing-
 * resource policy refused to touch it. Nothing changed. The exception names
 * the adopting options this caller is permitted to take, so an API client can
 * ask its human and resubmit with on_existing set.
 */
abstract class ResourceAlreadyExists extends DomainException
{
    /**
     * @param list<string> $options ExistingResourcePolicy values the caller may resubmit with
     */
    final protected function __construct(string $message, string $server, string $target, private readonly array $options)
    {
        parent::__construct(
            message: $message,
            userParams: ['target' => $target, 'server' => $server],
            context: ['target' => $target, 'server' => $server, 'options' => $options],
        );
    }

    /**
     * @param list<string> $options
     */
    public static function on(string $server, string $target, array $options): static
    {
        return new static(static::describe($server, $target), $server, $target, $options);
    }

    /**
     * The on_existing values the caller may resubmit with. Empty when the
     * caller may not adopt.
     *
     * @return list<string>
     */
    public function options(): array
    {
        return $this->options;
    }

    #[Override]
    public function render(Request $request): ?JsonResponse
    {
        $response = parent::render($request);

        $response?->setData([...(array) $response->getData(true), 'options' => $this->options]);

        return $response;
    }

    abstract protected static function describe(string $server, string $target): string;
}
