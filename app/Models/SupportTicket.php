<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int|null $vehicle_id
 * @property CarbonInterface|null $verified_at
 * @property CarbonInterface|null $closed_at
 */
#[Fillable(['system_id', 'contact_id', 'customer_id', 'vehicle_id', 'previous_ticket_id', 'problem_category_id', 'problem_subcategory_id', 'title', 'description', 'status', 'resolution', 'verification_source', 'verification_notes', 'verified_by', 'verified_at', 'closed_at'])]
class SupportTicket extends Model
{
    public const SUBCATEGORY_PIVOT = 'problem_subcategory_support_ticket';

    public const CLASSIFICATION_RELATIONS = ['problemCategory.systems', 'problemSubcategories.systems', 'problemSubcategories.category'];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'open'];

    /** @return BelongsTo<System, $this> */
    public function system(): BelongsTo
    {
        return $this->belongsTo(System::class);
    }

    /** @return BelongsTo<Contact, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Vehicle, $this> */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** @return BelongsTo<ProblemCategory, $this> */
    public function problemCategory(): BelongsTo
    {
        return $this->belongsTo(ProblemCategory::class);
    }

    /** @return BelongsToMany<ProblemSubcategory, $this> */
    public function problemSubcategories(): BelongsToMany
    {
        return $this->belongsToMany(ProblemSubcategory::class, self::SUBCATEGORY_PIVOT)
            ->withPivot('problem_category_id')->orderBy('problem_subcategories.id');
    }

    /** @return BelongsTo<SupportTicket, $this> */
    public function previousTicket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'previous_ticket_id');
    }

    /** @return HasMany<SupportTicket, $this> */
    public function followUpTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'previous_ticket_id');
    }

    /** @return HasMany<PendingTask, $this> */
    public function pendingTasks(): HasMany
    {
        return $this->hasMany(PendingTask::class);
    }

    /** @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }
}
