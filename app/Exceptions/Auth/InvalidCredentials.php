<?php

namespace App\Exceptions\Auth;

use Illuminate\Auth\AuthenticationException;

/**
 * Wrong email or password, or an account of the other role. One message for all of them, so the
 * response never reveals whether an email exists or which role it has. Rendered by Laravel as 401.
 */
class InvalidCredentials extends AuthenticationException
{
    public function __construct()
    {
        parent::__construct('These credentials do not match our records.');
    }
}
