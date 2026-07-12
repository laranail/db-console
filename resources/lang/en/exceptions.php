<?php

declare(strict_types=1);

/*
 * User-safe messages, one per ExceptionCode. These are what
 * DBConsoleException::userMessage() shows in the UI, CLI, and API —
 * sanitized by construction, never carrying secrets or raw driver detail.
 */
return [
    'identifier' => [
        'invalid' => "The :kind ':value' is not allowed. It must be :requirement.",
    ],

    'password' => [
        'weak' => 'The password is too weak: :reason.',
    ],

    'privilege' => [
        'unknown' => "The privilege ':privilege' is not on the allow-list.",
        'forbidden' => "The privilege ':privilege' is self-escalating or server-wide and can never be granted.",
    ],

    'engine' => [
        'unsupported_operation' => 'The :engine engine does not support :operation.',
        'statement_build_failure' => 'The engine could not build a valid statement for this operation.',
    ],

    'server' => [
        'unreachable' => "Server ':server' is unreachable over its admin connection.",
        'authentication_failed' => "The admin credentials for server ':server' were rejected.",
        'insufficient_privilege' => 'The admin account is missing a privilege needed for :operation.',
        'unknown' => "No server named ':server' is registered.",
        'misconfigured' => "Server ':server' is misconfigured: :problem.",
    ],

    'operation' => [
        'failed' => 'The operation failed at the server. Details are in the db-console log.',
    ],

    'rollback' => [
        'failed' => 'A rollback step (:step) failed — the server may be in a partial state and needs review.',
    ],

    'not_authorized' => 'You are not authorized to perform :ability at :scope.',

    'secret' => [
        'unavailable' => 'A stored credential could not be resolved from the :driver secret backend.',
        'driver_misconfigured' => 'The :driver secret driver is misconfigured: :problem.',
        'insecure_driver' => 'The app_key secret driver is blocked in production. Choose kms, vault, or reference, or set DB_CONSOLE_ALLOW_APPKEY_IN_PROD=true to accept the risk explicitly.',
    ],
];
