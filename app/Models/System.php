<?php

namespace App\Models;

use App\Models\Builders\SystemBuilder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'description', 'is_active'])]
#[UseEloquentBuilder(SystemBuilder::class)]
class System extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['is_active' => true];

    /** @return HasMany<SupportTicket, $this> */
    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    /** @return HasMany<KnowledgeArticle, $this> */
    public function knowledgeArticles(): HasMany
    {
        return $this->hasMany(KnowledgeArticle::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
