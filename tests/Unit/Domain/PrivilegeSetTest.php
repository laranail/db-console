<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\Privileges\PrivilegeSet;
use Simtabi\Laranail\DBConsole\Enums\ForbiddenPrivilege as ForbiddenPrivilegeEnum;
use Simtabi\Laranail\DBConsole\Enums\Privilege;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Exceptions\ForbiddenPrivilege;
use Simtabi\Laranail\DBConsole\Exceptions\UnknownPrivilege;

describe('preset composition (section 12 table)', function (): void {
    it('ReadOnly is read data + views', function (): void {
        expect(PrivilegeSet::fromPreset(PrivilegePreset::ReadOnly)->values())
            ->toBe(['select', 'show_view']);
    });

    it('ReadWrite adds insert/update/delete', function (): void {
        expect(PrivilegeSet::fromPreset(PrivilegePreset::ReadWrite)->values())
            ->toBe(['select', 'show_view', 'insert', 'update', 'delete']);
    });

    it('AppStandard adds create/alter/index/temp-tables/execute', function (): void {
        expect(PrivilegeSet::fromPreset(PrivilegePreset::AppStandard)->values())
            ->toBe([
                'select', 'show_view', 'insert', 'update', 'delete',
                'create', 'alter', 'index', 'create_temporary_tables', 'execute',
            ]);
    });

    it('Full is every allow-listed privilege — and nothing forbidden', function (): void {
        $full = PrivilegeSet::fromPreset(PrivilegePreset::Full);

        expect($full->privileges())->toHaveCount(count(Privilege::cases()))
            ->and($full->contains(Privilege::Drop))->toBeTrue();

        foreach (ForbiddenPrivilegeEnum::cases() as $forbidden) {
            expect(in_array($forbidden->value, $full->values(), true))->toBeFalse();
        }
    });

    it('the Custom preset requires an explicit list', function (): void {
        PrivilegeSet::fromPreset(PrivilegePreset::Custom);
    })->throws(InvalidArgumentException::class);
});

describe('the forbidden guard (must never be weakened)', function (): void {
    it('hard-blocks every forbidden privilege in every spelling', function (string $input): void {
        PrivilegeSet::custom([$input]);
    })->with([
        'GRANT OPTION', 'grant option', 'grant-option', 'WITH GRANT OPTION',
        'WITH ADMIN OPTION', 'SUPER', 'FILE', 'PROCESS', 'SHUTDOWN', 'RELOAD',
        'CREATE USER', 'REPLICATION CLIENT', 'REPLICATION SLAVE',
        'SUPERUSER', 'CREATEROLE', 'CREATEDB', 'REPLICATION', 'BYPASSRLS',
        'sysadmin', 'securityadmin', 'serveradmin', 'CONTROL SERVER',
    ])->throws(ForbiddenPrivilege::class);

    it('blocks every ForbiddenPrivilege enum case (guard reads the single source)', function (): void {
        foreach (Privilege::forbidden() as $forbidden) {
            expect(fn (): PrivilegeSet => PrivilegeSet::custom([$forbidden->value]))
                ->toThrow(ForbiddenPrivilege::class);
        }
    });

    it('Privilege::forbidden() IS the ForbiddenPrivilege enum', function (): void {
        expect(Privilege::forbidden())->toBe(ForbiddenPrivilegeEnum::cases());
    });

    it('the allow-list and block list are disjoint', function (): void {
        $allowed = Privilege::values();
        foreach (ForbiddenPrivilegeEnum::values() as $forbidden) {
            expect(in_array($forbidden, $allowed, true))->toBeFalse();
        }
    });

    it('rejects unknown privileges as unknown, not forbidden', function (): void {
        PrivilegeSet::custom(['FLY_TO_THE_MOON']);
    })->throws(UnknownPrivilege::class);

    it('names a forbidden privilege as forbidden even though it is also unknown to the allow-list', function (): void {
        PrivilegeSet::custom(['SELECT', 'GRANT OPTION']);
    })->throws(ForbiddenPrivilege::class);
});

describe('custom sets', function (): void {
    it('parses loose spellings onto the allow-list', function (): void {
        $set = PrivilegeSet::custom(['SELECT', 'create view', 'LOCK-TABLES']);

        expect($set->values())->toBe(['select', 'create_view', 'lock_tables'])
            ->and($set->preset)->toBe(PrivilegePreset::Custom);
    });

    it('deduplicates', function (): void {
        expect(PrivilegeSet::custom(['SELECT', 'select', Privilege::Select])->values())
            ->toBe(['select']);
    });

    it('rejects an empty set', function (): void {
        PrivilegeSet::custom([]);
    })->throws(InvalidArgumentException::class);
});
