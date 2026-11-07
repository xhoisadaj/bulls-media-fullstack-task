<?php

namespace App\Sync;

use RuntimeException;

/**
 * A planned schema change was rejected. `errors` lists every reason, for the UI.
 */
class SchemaException extends RuntimeException
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode(' ', $errors));
    }
}
