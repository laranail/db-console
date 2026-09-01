<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Audit;

use Simtabi\Laranail\DBConsole\Models\AuditLog;

/**
 * Tamper-evident hash chaining for the audit trail (section 7). Each row's
 * hash is SHA-256 over its canonical content plus the previous row's hash,
 * so altering or deleting any historical row breaks every hash after it —
 * detectable by verify() without trusting the storage.
 *
 * The chain is genesis-anchored: the first row links to a fixed seed.
 */
final class AuditChain
{
    private const string GENESIS = 'db-console:audit:genesis';

    /**
     * Compute the hash for a row given the previous hash and the row's
     * canonical, secret-free content.
     *
     * @param  array<string, mixed>  $content
     */
    public function hash(?string $previousHash, array $content): string
    {
        $canonical = json_encode($this->canonicalize($content), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', ($previousHash ?? self::GENESIS).'|'.$canonical);
    }

    /**
     * The canonical content of an AuditLog row for hashing — a fixed field
     * order, excluding the hash columns themselves.
     *
     * @return array<string, mixed>
     */
    public function contentOf(AuditLog $row): array
    {
        return [
            'action' => $row->action->value,
            'target' => $row->target,
            'server' => $row->server,
            'engine' => $row->engine?->value,
            'outcome' => $row->outcome->value,
            'sanitized_message' => $row->sanitized_message,
            'actor_type' => $row->getAttribute('actor_type'),
            'actor_id' => $row->getAttribute('actor_id'),
            'ip' => $row->ip,
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    /**
     * Walk the whole chain in insertion order and confirm every row's hash
     * still matches its content and links to its predecessor.
     *
     * @return array{valid: bool, checked: int, broken_at: ?string}
     */
    public function verify(): array
    {
        $previous = null;
        $checked = 0;

        /** @var iterable<int, AuditLog> $rows */
        $rows = AuditLog::query()->orderBy('created_at')->orderBy('id')->cursor();

        foreach ($rows as $row) {
            $checked++;
            $expected = $this->hash($previous, $this->contentOf($row));

            if (! hash_equals($expected, (string) $row->hash) || (string) $row->previous_hash !== (string) ($previous ?? '')) {
                return ['valid' => false, 'checked' => $checked, 'broken_at' => (string) $row->getKey()];
            }

            $previous = $row->hash;
        }

        return ['valid' => true, 'checked' => $checked, 'broken_at' => null];
    }

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function canonicalize(array $content): array
    {
        ksort($content);

        return $content;
    }
}
