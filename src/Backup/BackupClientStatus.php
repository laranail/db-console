<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Backup;

/**
 * Whether the dump client a server's backup-before-drop runs is usable now:
 * the tool, the binary it resolves to, and what was found. Read by doctor, so
 * a missing or too-old client is reported before a drop needs it.
 */
final readonly class BackupClientStatus
{
    public function __construct(
        public bool $ready,
        public string $tool,
        public string $binary,
        public string $message,
    ) {}
}
