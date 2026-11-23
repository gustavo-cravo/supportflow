<?php

namespace App\Models\Concerns;

use App\Support\ProblemName;

trait NormalizesProblemName
{
    public function setNameAttribute(string $value): void
    {
        $this->attributes['name'] = ProblemName::clean($value);
        $this->attributes['normalized_name'] = ProblemName::key($value);
    }
}
