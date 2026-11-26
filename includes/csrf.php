<?php
require_once __DIR__ . '/auth.php';

/** Generate a session CSRF token. */
function csrf_generate_token(): string
{
    return csrf_token();
}

/** Validate a submitted CSRF token. */
function csrf_validate($token): bool
{
    return csrf_valid($token);
}
