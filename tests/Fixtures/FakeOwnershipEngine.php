<?php

declare(strict_types=1);

namespace Simtabi\Laranail\DBConsole\Tests\Fixtures;

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Domain\Statement;
use Simtabi\Laranail\DBConsole\Domain\StatementList;
use Simtabi\Laranail\DBConsole\Engines\OwnershipReleasingEngine;

/**
 * Drives the role-drop orchestration against SQLite: "membership" is a
 * table that exists while the admin holds it, and each step can be made to
 * fail by pointing it at a table that does not exist.
 */
final class FakeOwnershipEngine implements OwnershipReleasingEngine
{
    public function __construct(
        public bool $alreadyHolds = false,
        public bool $blockedDatabase = false,
        public bool $dropFails = false,
        public bool $releaseMembershipFails = false,
    ) {}

    public function holdsPrivilegesOf(Username $u): StatementList
    {
        return new StatementList(Statement::plain('SELECT ' . ($this->alreadyHolds ? '1' : '0')));
    }

    public function takeMembership(Username $u): StatementList
    {
        return new StatementList(Statement::plain('CREATE TABLE membership (id INTEGER)'));
    }

    public function releaseMembership(Username $u): StatementList
    {
        return new StatementList(Statement::plain($this->releaseMembershipFails ? 'DROP TABLE no_such_table' : 'DROP TABLE membership'));
    }

    public function dependentDatabases(Username $u): StatementList
    {
        return new StatementList(Statement::plain(
            $this->blockedDatabase
                ? "SELECT 'locked_db' AS datname, 0 AS can_connect"
                : "SELECT 'x' AS datname, 1 AS can_connect WHERE 0",
        ));
    }

    public function releaseOwnershipIn(Username $u, array $databases): StatementList
    {
        return new StatementList;
    }

    public function dropReleasedAccount(Username $u, Host $h): StatementList
    {
        return new StatementList(Statement::plain($this->dropFails ? 'DROP TABLE no_such_role' : 'CREATE TABLE dropped (id INTEGER)'));
    }

    public function dropAccountReleasing(Username $u, Host $h, array $databases): StatementList
    {
        return new StatementList(...[...$this->takeMembership($u)->all(), ...$this->dropReleasedAccount($u, $h)->all()]);
    }
}
