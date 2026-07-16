<?php

namespace App\Models\Concerns;

use LogicException;

trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(fn ($model) => throw new LogicException(class_basename($model).' records are immutable.'));
        static::deleting(fn ($model) => throw new LogicException(class_basename($model).' records are immutable.'));
    }
}
