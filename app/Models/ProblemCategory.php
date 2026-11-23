<?php

namespace App\Models;

use App\Models\Concerns\NormalizesProblemName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_active'])]
class ProblemCategory extends Model
{
    use NormalizesProblemName;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @return BelongsToMany<System, $this> */
    public function systems(): BelongsToMany
    {
        return $this->belongsToMany(System::class, 'problem_category_system')->withPivot('is_active')->withTimestamps();
    }

    /** @return HasMany<ProblemSubcategory, $this> */
    public function subcategories(): HasMany
    {
        return $this->hasMany(ProblemSubcategory::class);
    }

    /** @return HasMany<SupportTicket, $this> */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
