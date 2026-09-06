<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Services\Catalog;

use Illuminate\Contracts\Auth\Guard;
use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Models\Grant;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Enums\GrantScope;
use Simtabi\Laranail\DBConsole\Models\DbAccount;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Models\ManagedDatabase;
use Simtabi\Laranail\DBConsole\Services\Contracts\Catalog;
use Simtabi\Laranail\DBConsole\Services\Results\AccountGrant;
use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;

/**
 * The Eloquent-backed catalog. Records what DBConsole did — ownership and
 * history metadata — never the source of truth about the server. Because
 * account username/host are encrypted at rest, lookups use a non-reversible
 * hash of (server, username, host) rather than a WHERE on the ciphertext.
 */
final readonly class DbConsoleCatalog implements Catalog
{
    public function __construct(private Guard $auth) {}

    public function recordDatabase(string $server, DbName $db, string $charset, ?string $collation): void
    {
        ManagedDatabase::query()->updateOrCreate(
            ['server_name' => $server, 'name' => $db->value],
            [
                'charset'    => $charset,
                'collation'  => $collation,
                'is_managed' => true,
                'created_by' => $this->actorId(),
            ],
        );
    }

    public function forgetDatabase(string $server, DbName $db): void
    {
        ManagedDatabase::query()
            ->where('server_name', $server)
            ->where('name', $db->value)
            ->delete();
    }

    public function recordAccount(string $server, Username $user, Host $host): void
    {
        $existing = $this->findAccount($server, $user, $host);
        if ($existing instanceof DbAccount) {
            return;
        }

        DbAccount::query()->create([
            'server_name'   => $server,
            'username'      => $user->value,
            'host'          => $host->value,
            'username_hash' => $this->accountHash($server, $user, $host),
            'is_managed'    => true,
            'created_by'    => $this->actorId(),
        ]);
    }

    public function forgetAccount(string $server, Username $user, Host $host): void
    {
        $this->findAccount($server, $user, $host)?->delete();
    }

    public function recordPasswordRotation(string $server, Username $user, Host $host): void
    {
        $this->findAccount($server, $user, $host)?->forceFill([
            'last_password_rotated_at' => now(),
        ])->save();
    }

    public function recordHostChange(string $server, Username $user, Host $oldHost, Host $newHost): void
    {
        $account = $this->findAccount($server, $user, $oldHost);
        $account?->forceFill([
            'host'          => $newHost->value,
            'username_hash' => $this->accountHash($server, $user, $newHost),
        ])->save();
    }

    public function recordGrant(string $server, Username $user, Host $host, DbName $db, PrivilegeSet $set): void
    {
        $account = $this->findAccount($server, $user, $host);
        $database = ManagedDatabase::query()
            ->where('server_name', $server)
            ->where('name', $db->value)
            ->first();

        if (! $account instanceof DbAccount || ! $database instanceof ManagedDatabase) {
            return;
        }

        Grant::query()->updateOrCreate(
            ['account_id' => $account->id, 'database_id' => $database->id],
            [
                'preset'     => $set->preset,
                'privileges' => $set->values(),
                'scope'      => GrantScope::Database,
                'granted_by' => $this->actorId(),
            ],
        );
    }

    public function forgetGrant(string $server, Username $user, Host $host, DbName $db): void
    {
        $account = $this->findAccount($server, $user, $host);
        $database = ManagedDatabase::query()
            ->where('server_name', $server)
            ->where('name', $db->value)
            ->first();

        if (! $account instanceof DbAccount || ! $database instanceof ManagedDatabase) {
            return;
        }

        Grant::query()
            ->where('account_id', $account->id)
            ->where('database_id', $database->id)
            ->delete();
    }

    public function grantsForAccount(string $server, Username $user, Host $host): array
    {
        $account = $this->findAccount($server, $user, $host);
        if (! $account instanceof DbAccount) {
            return [];
        }

        $grants = [];
        foreach ($account->grants()->with('database')->get() as $grant) {
            $database = $grant->database;
            if ($database === null) {
                continue;
            }

            $privileges = $grant->preset === PrivilegePreset::Custom
                ? PrivilegeSet::custom($grant->privileges)
                : PrivilegeSet::fromPreset($grant->preset);

            $grants[] = new AccountGrant(new DbName($database->name), $privileges);
        }

        return $grants;
    }

    private function findAccount(string $server, Username $user, Host $host): ?DbAccount
    {
        return DbAccount::query()
            ->where('server_name', $server)
            ->where('username_hash', $this->accountHash($server, $user, $host))
            ->first();
    }

    /**
     * A non-reversible lookup key over the encrypted identity. Uses the app
     * key so the hash cannot be precomputed from names alone.
     */
    private function accountHash(string $server, Username $user, Host $host): string
    {
        $appKey = (string) config('app.key', '');

        return hash_hmac('sha256', "{$server}|{$user->value}|{$host->value}", $appKey);
    }

    private function actorId(): ?string
    {
        $id = $this->auth->id();

        return $id === null ? null : (string) $id;
    }
}
