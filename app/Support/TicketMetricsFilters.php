<?php

namespace App\Support;

use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

final readonly class TicketMetricsFilters
{
    public const TIMEZONE = 'America/Sao_Paulo';

    public function __construct(
        public string $period,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public ?int $systemId,
        public string $status,
        public ?int $categoryId,
        public ?int $subcategoryId,
        public string $classification,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromValidated(array $data): self
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $period = $data['period'] ?? 'last_6_months';
        $from = match ($period) {
            'this_month' => $today->startOfMonth(),
            'last_30_days' => $today->subDays(29)->startOfDay(),
            'last_3_months' => $today->startOfMonth()->subMonths(2),
            'last_6_months' => $today->startOfMonth()->subMonths(5),
            'this_year' => $today->startOfYear(),
            'custom' => CarbonImmutable::parse($data['date_from'], self::TIMEZONE)->startOfDay(),
            default => null,
        };
        $to = match ($period) {
            'all' => null,
            'custom' => CarbonImmutable::parse($data['date_to'], self::TIMEZONE)->startOfDay(),
            default => $today,
        };

        return new self($period, $from, $to,
            isset($data['system_id']) ? (int) $data['system_id'] : null,
            $data['status'] ?? 'all',
            isset($data['problem_category_id']) ? (int) $data['problem_category_id'] : null,
            isset($data['problem_subcategory_id']) ? (int) $data['problem_subcategory_id'] : null,
            $data['classification'] ?? 'all');
    }

    public function apply(Builder $query): Builder
    {
        // created_at follows the existing application's Sao Paulo wall-clock storage.
        // Keep the column unwrapped so period filters can use its indexes.
        if ($this->from !== null && $this->to !== null) {
            $query->where('support_tickets.created_at', '>=', $this->from->format('Y-m-d H:i:s'))
                ->where('support_tickets.created_at', '<', $this->to->addDay()->startOfDay()->format('Y-m-d H:i:s'));
        }
        if ($this->systemId !== null) {
            $query->where('support_tickets.system_id', $this->systemId);
        }
        if ($this->status === 'not_closed') {
            $query->where('support_tickets.status', '!=', TicketStatus::Closed->value);
        } elseif ($this->status !== 'all') {
            $query->where('support_tickets.status', $this->status);
        }
        if ($this->categoryId !== null) {
            $query->where('support_tickets.problem_category_id', $this->categoryId);
        }
        if ($this->subcategoryId !== null) {
            $query->where('support_tickets.problem_subcategory_id', $this->subcategoryId);
        }
        if ($this->classification === 'unclassified') {
            $query->whereNull('support_tickets.problem_category_id');
        } elseif ($this->classification === 'without_subcategory') {
            $query->whereNotNull('support_tickets.problem_category_id')->whereNull('support_tickets.problem_subcategory_id');
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'date_from' => $this->from?->toDateString(),
            'date_to' => $this->to?->toDateString(),
            'timezone' => self::TIMEZONE,
            'system_id' => $this->systemId,
            'status' => $this->status,
            'problem_category_id' => $this->categoryId,
            'problem_subcategory_id' => $this->subcategoryId,
            'classification' => $this->classification,
        ];
    }
}
