<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tunnels;

use RuntimeException;
use SensitiveParameter;

/**
 * A 0700 directory holding a tunnel's files: the generated known_hosts, a
 * private key taken from the secrets driver, and a local socket. Because no
 * other user can enter the directory, nothing written into it is readable by
 * them even for the moment before its own mode is set.
 */
final class PrivateDirectory
{
    private bool $removed = false;

    private function __construct(public readonly string $path) {}

    public function __destruct()
    {
        $this->remove();
    }

    public static function create(string $base): self
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR);
        if (! is_dir($base) || ! is_writable($base)) {
            throw new RuntimeException("the tunnel directory base '{$base}' is not a writable directory");
        }

        // Short on purpose: a Unix socket path is limited to about 104 bytes.
        $path = $base . DIRECTORY_SEPARATOR . 'dbc-' . bin2hex(random_bytes(4));

        $umask = umask(0o077);
        try {
            if (! @mkdir($path, 0o700)) {
                throw new RuntimeException("could not create the tunnel directory '{$path}'");
            }
        } finally {
            umask($umask);
        }

        chmod($path, 0o700);

        return new self($path);
    }

    /**
     * Write a file readable by this user only, and return its path.
     */
    public function write(string $name, #[SensitiveParameter] string $contents): string
    {
        $file = $this->file($name);

        $umask = umask(0o077);
        try {
            if (file_put_contents($file, $contents, LOCK_EX) === false) {
                throw new RuntimeException("could not write '{$file}'");
            }
        } finally {
            umask($umask);
        }

        chmod($file, 0o600);

        return $file;
    }

    public function file(string $name): string
    {
        return $this->path . DIRECTORY_SEPARATOR . $name;
    }

    /**
     * Remove every file and the directory itself. Idempotent.
     */
    public function remove(): void
    {
        if ($this->removed) {
            return;
        }

        $this->removed = true;

        foreach (glob($this->path . DIRECTORY_SEPARATOR . '{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->path);
    }
}
