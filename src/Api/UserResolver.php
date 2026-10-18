<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Api;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Finds a console user by id on rbac.user_model. One place for the lookup
 * the token and role commands each carried a copy of, and the API now needs.
 */
final readonly class UserResolver
{
    public function __construct(private Config $config) {}

    public function find(int|string $id): ?Authenticatable
    {
        /** @var class-string<Model> $model */
        $model = (string) $this->config->get('laranail.db-console.rbac.user_model', '\App\Models\User');
        if ((string) $id === '' || ! class_exists($model)) {
            return null;
        }

        $user = $model::query()->find($id);

        return $user instanceof Authenticatable ? $user : null;
    }
}
