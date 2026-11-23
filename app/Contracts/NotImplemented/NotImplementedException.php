<?php

namespace App\Contracts\NotImplemented;

use LogicException;

/** A contract was called before its owning session bound a real implementation. */
class NotImplementedException extends LogicException
{
    /** @param  class-string  $contract */
    public static function for(string $contract, string $session): self
    {
        return new self("{$contract} is not implemented yet ({$session}). Its owner binds it in its own service provider.");
    }
}
