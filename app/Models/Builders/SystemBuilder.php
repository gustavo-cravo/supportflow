<?php

namespace App\Models\Builders;

use App\Models\System;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/** @extends Builder<System> */
class SystemBuilder extends Builder
{
    public function delete(): never
    {
        throw new LogicException('Systems cannot be deleted. Set is_active to false instead.');
    }

    public function forceDelete(): never
    {
        throw new LogicException('Systems cannot be deleted. Set is_active to false instead.');
    }

    public function truncate(): never
    {
        throw new LogicException('Systems cannot be deleted. Set is_active to false instead.');
    }
}
