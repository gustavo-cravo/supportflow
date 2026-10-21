<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonInterface|null $due_at
 * @property CarbonInterface|null $completed_at
 */
#[Fillable(['support_ticket_id', 'description', 'due_at', 'status', 'completion_notes', 'completed_at'])]
class PendingTask extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /** @return BelongsTo<SupportTicket, $this> */
    public function supportTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
