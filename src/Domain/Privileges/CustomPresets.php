<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Domain\Privileges;

use Simtabi\Laranail\DBConsole\Enums\Privilege;
use Simtabi\Laranail\DBConsole\Enums\PrivilegePreset;
use Simtabi\Laranail\DBConsole\Exceptions\DomainException;
use Simtabi\Laranail\DBConsole\Exceptions\PresetMisconfigured;

/**
 * The named custom presets of config('laranail.db-console.presets'), each a
 * non-empty list of privilege tokens checked by PrivilegeSet::custom(): a
 * forbidden or unknown privilege, an empty list, or a name a shipped
 * PrivilegePreset already uses is refused with PresetMisconfigured.
 */
final readonly class CustomPresets
{
    /**
     * @param array<string, PrivilegeSet> $presets
     */
    private function __construct(private array $presets) {}

    public static function fromConfig(mixed $raw): self
    {
        if (! is_array($raw)) {
            throw PresetMisconfigured::named('presets', 'presets must be an array of name => privileges');
        }

        $presets = [];
        foreach ($raw as $name => $privileges) {
            $label = (string) $name;

            if (! is_string($name) || trim($name) === '') {
                throw PresetMisconfigured::named($label, 'a preset needs a name as its key');
            }

            if (PrivilegePreset::tryFrom($name) instanceof PrivilegePreset) {
                throw PresetMisconfigured::named($label, 'the name is a shipped preset');
            }

            if (! is_array($privileges) || ! array_is_list($privileges) || $privileges === []) {
                throw PresetMisconfigured::named($label, 'its privileges must be a non-empty list');
            }

            foreach ($privileges as $privilege) {
                if (! is_string($privilege)) {
                    throw PresetMisconfigured::named($label, 'every privilege must be a string');
                }
            }

            try {
                /** @var list<string> $privileges */
                $presets[$name] = PrivilegeSet::custom($privileges);
            } catch (DomainException $e) {
                throw PresetMisconfigured::named($label, $e->getMessage());
            }
        }

        return new self($presets);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->presets);
    }

    /**
     * @return list<Privilege>
     */
    public function privileges(string $name): array
    {
        return isset($this->presets[$name]) ? $this->presets[$name]->privileges() : [];
    }
}
