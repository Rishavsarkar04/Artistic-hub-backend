<?php

namespace App\Exceptions\Auth;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * Correct credentials, but the account is blocked, suspended or pending. Only raised after the
 * password matched. Rendered by Laravel as 403.
 */
class AccountNotActive extends AuthorizationException
{
    public function __construct()
    {
        parent::__construct('This account has been deactivated. Please contact us for help.');
    }
}
