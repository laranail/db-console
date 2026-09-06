<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Secrets\Reference;

use Throwable;
use Simtabi\Laranail\DBConsole\Secrets\Secret;
use Illuminate\Http\Client\Factory as HttpFactory;
use Simtabi\Laranail\DBConsole\Exceptions\SecretUnavailable;
use Simtabi\Laranail\DBConsole\Secrets\Contracts\ReferenceResolver;
use Simtabi\Laranail\DBConsole\Exceptions\SecretDriverMisconfigured;

/**
 * Resolves a Doppler pointer ("project/config/NAME") via Doppler's HTTP API
 * using a service token. No SDK — uses Laravel's HTTP client.
 *
 * @param array{token: ?string} $config
 */
final readonly class DopplerResolver implements ReferenceResolver
{
    /**
     * @param array{token: ?string} $config
     */
    public function __construct(
        private HttpFactory $http,
        private array $config = ['token' => null],
    ) {}

    public function resolve(string $pointer): Secret
    {
        $token = $this->config['token'] ?? null;
        if (! is_string($token) || $token === '') {
            throw SecretDriverMisconfigured::forDriver('reference', 'Doppler resolver needs a service token');
        }

        $parts = explode('/', $pointer);
        if (count($parts) !== 3) {
            throw SecretDriverMisconfigured::forDriver('reference', "Doppler pointer must be 'project/config/NAME', got '{$pointer}'");
        }

        [$project, $config, $name] = $parts;

        try {
            $response = $this->http
                ->baseUrl('https://api.doppler.com')
                ->withToken($token)
                ->acceptJson()
                ->get('/v3/configs/config/secret', [
                    'project' => $project,
                    'config'  => $config,
                    'name'    => $name,
                ]);

            $value = $response->json('value.computed');
            if (! is_string($value)) {
                throw SecretUnavailable::forReference($pointer, $this->provider());
            }

            return new Secret($value);
        } catch (SecretDriverMisconfigured|SecretUnavailable $e) {
            throw $e;
        } catch (Throwable $e) {
            throw SecretUnavailable::forReference($pointer, $this->provider(), $e);
        }
    }

    public function provider(): string
    {
        return 'doppler';
    }
}
