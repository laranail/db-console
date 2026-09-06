<?php

declare(strict_types=1);

use Simtabi\Laranail\DBConsole\Domain\Host;
use Simtabi\Laranail\DBConsole\Domain\DbName;
use Simtabi\Laranail\DBConsole\Domain\Charset;
use Simtabi\Laranail\DBConsole\Domain\Username;
use Simtabi\Laranail\DBConsole\Exceptions\InvalidIdentifier;

/*
 * THE injection corpus. Identifiers cannot be bound as query parameters,
 * so constructor-time allow-listing is the primary injection defense.
 * This suite must never be weakened.
 */

/** @return array<string, list<string>> */
function injectionCorpus(): array
{
    return [
        'backtick'                => ['shop`db'],
        'double backtick escape'  => ['shop``db'],
        'single quote'            => ["shop'db"],
        'double quote'            => ['shop"db'],
        'semicolon piggyback'     => ['shop;DROP TABLE users'],
        'semicolon drop database' => ['shop_db; DROP DATABASE shop'],
        'line comment'            => ['shop--db'],
        'block comment'           => ['shop/*db*/'],
        'space'                   => ['shop db'],
        'tab'                     => ["shop\tdb"],
        'newline'                 => ["shop\ndb"],
        'carriage return'         => ["shop\rdb"],
        'null byte'               => ["shop\0db"],
        'dot qualifier'           => ['mysql.user'],
        'percent wildcard'        => ['shop%db'],
        'asterisk'                => ['shop*'],
        'parentheses'             => ['shop(db)'],
        'angle brackets'          => ['shop<db>'],
        'square brackets'         => ['shop[db]'],
        'backslash'               => ['shop\\db'],
        'dollar interpolation'    => ['shop${db}'],
        'at sign'                 => ['user@host'],
        'equals'                  => ['shop=db'],
        'cyrillic homoglyph o'    => ['shоp'],
        'cyrillic homoglyph h'    => ['sһop'],
        'fullwidth latin'         => ['ｓhop'],
        'zero-width space'        => ["shop\u{200B}db"],
        'combining accent'        => ["shop\u{0301}"],
        'emoji'                   => ['shop💣'],
        'empty string'            => [''],
    ];
}

describe('DbName rejects the injection corpus', function (): void {
    it('throws InvalidIdentifier', function (string $input): void {
        new DbName($input);
    })->with(injectionCorpus())->throws(InvalidIdentifier::class);

    it('throws on an overlong name (65 chars, MySQL limit is 64)', function (): void {
        new DbName(str_repeat('a', 65));
    })->throws(InvalidIdentifier::class);
});

describe('Username rejects the injection corpus', function (): void {
    it('throws InvalidIdentifier', function (string $input): void {
        new Username($input);
    })->with(injectionCorpus())->throws(InvalidIdentifier::class);

    it('throws on an overlong username (33 chars, MySQL limit is 32)', function (): void {
        new Username(str_repeat('a', 33));
    })->throws(InvalidIdentifier::class);
});

describe('Host rejects hostile input', function (): void {
    it('throws InvalidIdentifier', function (string $input): void {
        new Host($input);
    })->with([
        'backtick'                 => ['10.0.%`'],
        'single quote'             => ["10.0.0.1'"],
        'double quote'             => ['10.0.0.1"'],
        'semicolon piggyback'      => ['localhost;DROP TABLE users'],
        'space'                    => ['local host'],
        'newline'                  => ["10.0.\n%"],
        'null byte'                => ["localhost\0"],
        'at sign'                  => ['user@10.0.0.1'],
        'slash comment'            => ['10.0/*x*/.1'],
        'backslash'                => ['host\\name'],
        'parentheses'              => ['host()'],
        'cyrillic homoglyph'       => ['lоcalhost'],
        'zero-width space'         => ["local\u{200B}host"],
        'ipv6 (unsupported in v1)' => ['::1'],
        'empty string'             => [''],
        'overlong (256 chars)'     => [str_repeat('a', 256)],
    ])->throws(InvalidIdentifier::class);
});

describe('valid identifiers are accepted', function (): void {
    it('accepts well-formed database names', function (string $input): void {
        expect(new DbName($input)->value)->toBe($input);
    })->with(['shop_prod', 'a', 'Shop123', '_internal', str_repeat('a', 64)]);

    it('accepts well-formed usernames', function (string $input): void {
        expect(new Username($input)->value)->toBe($input);
    })->with(['shop_user', 'svc_billing', 'A1', str_repeat('u', 32)]);

    it('accepts well-formed hosts including % wildcards', function (string $input): void {
        expect(new Host($input)->value)->toBe($input);
    })->with(['localhost', '%', '10.0.%', '192.168.1.100', 'db-server.internal', 'app_server']);
});

describe('Charset validation', function (): void {
    it('accepts a known charset with collation', function (): void {
        $charset = new Charset('utf8mb4', 'utf8mb4_unicode_ci');

        expect($charset->value)->toBe('utf8mb4')
            ->and($charset->collation)->toBe('utf8mb4_unicode_ci');
    });

    it('rejects hostile charsets', function (string $input): void {
        new Charset($input);
    })->with(['utf8mb4`', "utf8'", 'utf8;DROP', 'utf 8', ''])->throws(InvalidIdentifier::class);

    it('rejects hostile collations', function (): void {
        new Charset('utf8mb4', "utf8mb4' OR 1=1");
    })->throws(InvalidIdentifier::class);
});
