<?php

declare(strict_types=1);

/*
 * Validation messages for rules that are not backed by a domain exception.
 * Rules wrapping a value object reuse the exception message from
 * exceptions.php, so the wording is identical across VO, rule, and prompt.
 */
return [
    'string' => 'The :attribute field must be a string.',
    'server_name' => 'The :attribute field must be a valid server name (1-64 characters of letters, digits, underscore, or hyphen).',
    'scope' => "The :attribute field must be 'global', 'server:<name>', or 'database:<server>/<database>' (a trailing * wildcard is allowed on the database).",
    'confirm_name' => 'Type the database name exactly to confirm this destructive action.',
    'confirm_username' => 'Type the username exactly to confirm this destructive action.',
];
