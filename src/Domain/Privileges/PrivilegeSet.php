<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain\Privileges;

use InvalidArgumentException;
use Simtabi\Laranail\DBConsole\Enums\ForbiddenPrivilege as ForbiddenPrivilegeEnum;
use Simtabi\Laranail\DBConsole\Enums\Privilege;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Exceptions\ForbiddenPrivilege;
use Simtabi\Laranail\DBConsole\Exceptions\UnknownPrivilege;

/**
 * A validated, deduplicated set of privileges with its preset provenance.
 * The only ways in are fromPreset() and custom(); custom parsing checks
 * every token against the hard-block list first (the single source is
 * Privilege::forbidden()), then the allow-list — a forbidden or unknown
 * privilege can never exist inside a PrivilegeSet.
 */
final readonly class PrivilegeSet
{
    /** @var list<Privilege> */
    private array $privileges;

    /**
     * @param  list<Privilege>  $privileges
     */
    private function __construct(
        public PrivilegePreset $preset,
        array $privileges,
    ) {
        if ($privileges === []) {
            throw new InvalidArgumentException('a privilege set cannot be empty');
        }

        $unique = [];
        foreach ($privileges as $privilege) {
            $unique[$privilege->value] = $privilege;
        }

        $this->privileges = array_values($unique);
    }

    public static function fromPreset(PrivilegePreset $preset): self
    {
        if ($preset === PrivilegePreset::Custom) {
            throw new InvalidArgumentException(
                'the Custom preset needs an explicit privilege list; use PrivilegeSet::custom()',
            );
        }

        return new self($preset, $preset->privileges());
    }

    /**
     * Build a custom set from loose operator input. Forbidden tokens throw
     * before unknown ones, so a GRANT OPTION attempt is always named as
     * forbidden rather than merely unknown.
     *
     * @param  list<Privilege|string>  $privileges
     */
    public static function custom(array $privileges): self
    {
        $resolved = [];
        foreach ($privileges as $input) {
            if ($input instanceof Privilege) {
                $resolved[] = $input;

                continue;
            }

            $forbidden = ForbiddenPrivilegeEnum::tryFromLoose($input);
            if ($forbidden instanceof ForbiddenPrivilegeEnum) {
                throw ForbiddenPrivilege::forPrivilege($forbidden);
            }

            $privilege = Privilege::tryFromLoose($input);
            if (! $privilege instanceof Privilege) {
                throw UnknownPrivilege::forToken($input);
            }

            $resolved[] = $privilege;
        }

        return new self(PrivilegePreset::Custom, $resolved);
    }

    /**
     * @return list<Privilege>
     */
    public function privileges(): array
    {
        return $this->privileges;
    }

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(static fn (Privilege $p): string => $p->value, $this->privileges);
    }

    public function contains(Privilege $privilege): bool
    {
        return in_array($privilege, $this->privileges, true);
    }
}
