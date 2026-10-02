<?php

namespace App\Exceptions\Accounts;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** The customer has not created their profile yet, which this action needs. Rendered as 409. */
class ProfileRequired extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Create your profile first.');
    }
}
