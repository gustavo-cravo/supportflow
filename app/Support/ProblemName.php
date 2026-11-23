<?php

namespace App\Support;

use Illuminate\Support\Str;

class ProblemName
{
    public static function clean(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    public static function key(string $name): string
    {
        return Str::lower(self::clean($name));
    }
}
