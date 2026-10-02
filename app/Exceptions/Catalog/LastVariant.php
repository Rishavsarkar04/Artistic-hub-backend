<?php

namespace App\Exceptions\Catalog;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** A product must keep at least one variant. Rendered as 409; delete the product instead. */
class LastVariant extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('A product needs at least one variant. Delete the product instead.');
    }
}
