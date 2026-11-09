<?php

namespace App\Exceptions;

/**
 * "Fencing": thrown when the backup REFUSES a copy because it has already seen a
 * newer epoch. That means another server has become the primary while we were away,
 * so this server must undo its work and step down to backup.
 */
class FencedException extends \RuntimeException
{
    // $newerEpoch = the epoch the other server told us about
    public function __construct(public int $newerEpoch = 0)
    {
        parent::__construct("A newer primary exists (epoch {$newerEpoch}).");
    }
}