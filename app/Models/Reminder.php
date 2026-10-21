<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['support_ticket_id', 'title', 'description', 'scheduled_at', 'completed_at'])]
class Reminder extends Model
{
    /** @return BelongsTo<SupportTicket, $this> */
    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
