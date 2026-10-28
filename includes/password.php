<?php
/**
 * Password policy: 8-16 characters, with an uppercase letter, a lowercase letter,
 * a number and a special character.
 * Returns null when the password is acceptable, otherwise a message saying what is missing.
 */
const PASSWORD_POLICY_HINT = '8-16 characters with uppercase and lowercase letters, a number and a special character.';

function password_policy_error(string $pw): ?string
{
    $len = strlen($pw);
    if ($len < 8 || $len > 16)               return 'Password must be 8 to 16 characters long.';
    if (!preg_match('/[a-z]/', $pw))         return 'Password must include a lowercase letter.';
    if (!preg_match('/[A-Z]/', $pw))         return 'Password must include an uppercase letter.';
    if (!preg_match('/[0-9]/', $pw))         return 'Password must include a number.';
    if (!preg_match('/[^A-Za-z0-9\s]/', $pw)) return 'Password must include a special character (e.g. ! @ # $ %).';
    return null;
}
