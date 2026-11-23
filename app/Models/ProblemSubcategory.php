<?php

namespace App\Models;

use App\Models\Concerns\NormalizesProblemName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['problem_category_id', 'name', 'description', 'is_active'])]
class ProblemSubcategory extends Model
{
    use NormalizesProblemName;

    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @return BelongsTo<ProblemCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProblemCategory::class, 'problem_category_id');
    }

    /** @return BelongsToMany<System, $this> */
    public function systems(): BelongsToMany
    {
        return $this->belongsToMany(System::class, 'problem_subcategory_system')->withPivot('problem_category_id', 'is_active')->withTimestamps();
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
