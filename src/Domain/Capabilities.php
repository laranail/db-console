<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain;

/**
 * What one engine can honestly do. The UI and CLI gray out (or hide)
 * operations the active engine doesn't support instead of failing at
 * runtime — this object is what lets one codebase span engines whose
 * account models genuinely differ.
 *
 * The engine supplies what it can do anywhere; withHosting() adds what the
 * server's hosting means (ServerRegistry::capabilities() does both).
 */
final readonly class Capabilities
{
    public function __construct(
        public bool $canCreateDatabase,
        public bool $canCreateAccount,
        public bool $canScopeAccountsByHost,
        public bool $canGrantTableLevel,
        public bool $canRotatePassword,
        public EncryptionCapabilities $encryption,
        public ?string $accountModelNote = null,
        public ?HostingProfile $hosting = null,
    ) {}

    public function withHosting(HostingProfile $hosting): self
    {
        return new self(
            canCreateDatabase: $this->canCreateDatabase,
            canCreateAccount: $this->canCreateAccount,
            canScopeAccountsByHost: $this->canScopeAccountsByHost,
            canGrantTableLevel: $this->canGrantTableLevel,
            canRotatePassword: $this->canRotatePassword,
            encryption: $this->encryption,
            accountModelNote: $this->accountModelNote,
            hosting: $hosting,
        );
    }

    /**
     * Flat form for doctor output and API resources.
     *
     * @return array<string, bool|string|null>
     */
    public function toArray(): array
    {
        return [
            'can_create_database'        => $this->canCreateDatabase,
            'can_create_account'         => $this->canCreateAccount,
            'can_scope_accounts_by_host' => $this->canScopeAccountsByHost,
            'can_grant_table_level'      => $this->canGrantTableLevel,
            'can_rotate_password'        => $this->canRotatePassword,
            'can_read_at_rest_status'    => $this->encryption->canReadAtRestStatus,
            'can_require_tls_on_account' => $this->encryption->canRequireTlsOnAccount,
            'at_rest_mechanism'          => $this->encryption->atRestMechanism,
            'account_model_note'         => $this->accountModelNote,
            ...($this->hosting instanceof HostingProfile ? [
                'hosting'              => $this->hosting->hosting->value,
                'default_account_host' => $this->hosting->defaultAccountHost,
                'provider_backups'     => $this->hosting->providerBackups,
            ] : []),
        ];
    }
}
