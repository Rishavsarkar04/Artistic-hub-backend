<?php

namespace App\Exceptions\Accounts;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A second profile-creation request for the same customer. Rendered as 409; update the profile instead. */
class ProfileAlreadyExists extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Your profile already exists. Update it instead.');
    }
}
