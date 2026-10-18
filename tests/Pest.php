<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Tests\TestCase;
use Simtabi\Laranail\DBConsole\Domain\Password;

uses(TestCase::class)->in('Unit', 'Feature', 'Architecture');

/**
 * A strong password generated for this test run, never a literal in the
 * source. A fixed sample here was reported by secret scanning as a leaked
 * credential, and every copy of it was one more place to triage. Memoised per
 * length so every call in one run agrees; the value differs from run to run.
 */
function testPassword(int $length = 18): string
{
    static $passwords = [];

    return $passwords[$length] ??= Password::generate($length)->reveal();
}
