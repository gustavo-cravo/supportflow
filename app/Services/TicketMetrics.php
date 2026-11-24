<?php

namespace App\Services;

use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use App\Support\TicketMetricsFilters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Facades\DB;

class TicketMetrics
{
    private function query(TicketMetricsFilters $filters): Builder
    {
        return $filters->apply(DB::table('support_tickets'));
    }

    private function withCounts(Builder $query): Builder
    {
        return $query->selectRaw('COUNT(*) AS total, SUM(CASE WHEN support_tickets.status = ? THEN 1 ELSE 0 END) AS closed', [TicketStatus::Closed->value]);
    }

    /** @return array<string, int> */
    public function summary(TicketMetricsFilters $filters): array
    {
        $row = $this->withCounts($this->query($filters))
            ->selectRaw('COUNT(DISTINCT support_tickets.system_id) AS systems_count, SUM(CASE WHEN support_tickets.problem_category_id IS NULL THEN 1 ELSE 0 END) AS unclassified')
            ->selectSub($this->subcategoryQuery($filters)->selectRaw('COUNT(ticket_subcategory.problem_subcategory_id)'), 'subcategory_occurrences')->first();
        $total = (int) $row->total;
        $closed = (int) $row->closed;

        return ['total' => $total, 'closed' => $closed, 'not_closed' => $total - $closed,
            'systems_count' => (int) $row->systems_count, 'unclassified' => (int) $row->unclassified,
            'subcategory_occurrences' => (int) $row->subcategory_occurrences];
    }

    /** @return list<array<string, mixed>> */
    public function systems(TicketMetricsFilters $filters): array
    {
        $rows = $this->withCounts($this->query($filters)->join('systems', 'systems.id', '=', 'support_tickets.system_id'))
            ->addSelect('systems.id AS system_id', 'systems.name AS system_name', 'systems.is_active')
            ->groupBy('systems.id', 'systems.name', 'systems.is_active')->orderByDesc('total')->orderBy('systems.id')->get();

        return array_values($rows->map(fn ($row) => [
            'system_id' => (int) $row->system_id, 'system_name' => $row->system_name, 'is_active' => (bool) $row->is_active,
            'total' => (int) $row->total, 'closed' => (int) $row->closed, 'not_closed' => (int) $row->total - (int) $row->closed,
        ])->all());
    }

    /** @return list<array<string, mixed>> */
    public function categories(TicketMetricsFilters $filters): array
    {
        $rows = $this->query($filters)->join('systems', 'systems.id', '=', 'support_tickets.system_id')
            ->leftJoin('problem_categories AS category', 'category.id', '=', 'support_tickets.problem_category_id')
            ->select('support_tickets.problem_category_id', 'category.name AS category_name', 'category.is_active AS category_active',
                'systems.id AS system_id', 'systems.name AS system_name', 'systems.is_active AS system_active')
            ->selectRaw('COUNT(*) AS total')
            ->groupBy('support_tickets.problem_category_id', 'category.name', 'category.is_active', 'systems.id', 'systems.name', 'systems.is_active')->get();
        $total = (int) $rows->sum('total');
        $groups = [];
        foreach ($rows as $row) {
            $key = $row->problem_category_id ?? 'unclassified';
            $groups[$key] ??= ['problem_category_id' => $row->problem_category_id === null ? null : (int) $row->problem_category_id,
                'category_name' => $row->category_name ?? 'Sem classificação', 'is_active' => $row->category_active === null ? null : (bool) $row->category_active,
                'total' => 0, 'percentage' => 0.0, 'systems' => []];
            $groups[$key]['total'] += (int) $row->total;
            $groups[$key]['systems'][] = ['system_id' => (int) $row->system_id, 'system_name' => $row->system_name,
                'is_active' => (bool) $row->system_active, 'total' => (int) $row->total];
        }
        foreach ($groups as &$group) {
            $group['percentage'] = $this->percentage($group['total'], $total);
            $group['systems'] = $this->rank($group['systems'], 'system_id');
        }
        unset($group);

        return $this->rank(array_values($groups), 'problem_category_id');
    }

    /**
     * @param  array<int|string, int>|null  $categoryTotals
     * @return list<array<string, mixed>>
     */
    public function subcategories(TicketMetricsFilters $filters, ?array $categoryTotals = null): array
    {
        $rows = $this->subcategoryQuery($filters)->join('systems', 'systems.id', '=', 'support_tickets.system_id')
            ->leftJoin('problem_categories AS category', 'category.id', '=', 'support_tickets.problem_category_id')
            ->leftJoin('problem_subcategories AS subcategory', 'subcategory.id', '=', 'ticket_subcategory.problem_subcategory_id')
            ->select('support_tickets.problem_category_id', 'category.name AS category_name', 'category.is_active AS category_active',
                'ticket_subcategory.problem_subcategory_id', 'subcategory.name AS subcategory_name', 'subcategory.is_active AS subcategory_active',
                'systems.id AS system_id', 'systems.name AS system_name', 'systems.is_active AS system_active')
            ->selectRaw('COUNT(*) AS total')->groupBy('support_tickets.problem_category_id', 'category.name', 'category.is_active',
                'ticket_subcategory.problem_subcategory_id', 'subcategory.name', 'subcategory.is_active', 'systems.id', 'systems.name', 'systems.is_active')->get();
        $categoryTotals ??= $this->query($filters)->selectRaw('support_tickets.problem_category_id, COUNT(*) AS total')
            ->groupBy('support_tickets.problem_category_id')->pluck('total', 'problem_category_id')->all();
        $groups = [];
        foreach ($rows as $row) {
            $categoryKey = $row->problem_category_id ?? 'unclassified';
            $key = $categoryKey.':'.($row->problem_subcategory_id ?? 'none');
            $groups[$key] ??= ['problem_category_id' => $row->problem_category_id === null ? null : (int) $row->problem_category_id,
                'category_name' => $row->category_name ?? 'Sem classificação', 'category_is_active' => $row->category_active === null ? null : (bool) $row->category_active,
                'problem_subcategory_id' => $row->problem_subcategory_id === null ? null : (int) $row->problem_subcategory_id,
                'subcategory_name' => $row->subcategory_name ?? ($row->problem_category_id === null ? 'Sem classificação' : 'Sem subcategoria'),
                'is_active' => $row->subcategory_active === null ? null : (bool) $row->subcategory_active,
                'total' => 0, 'category_total' => 0, 'percentage' => 0.0, 'systems' => []];
            $groups[$key]['total'] += (int) $row->total;
            $groups[$key]['systems'][] = ['system_id' => (int) $row->system_id, 'system_name' => $row->system_name,
                'is_active' => (bool) $row->system_active, 'total' => (int) $row->total];
        }
        foreach ($groups as &$group) {
            $group['category_total'] = (int) $categoryTotals[$group['problem_category_id'] ?? ''];
            $group['percentage'] = $this->percentage($group['total'], $group['category_total']);
            $group['systems'] = $this->rank($group['systems'], 'system_id');
        }
        unset($group);

        return $this->rank(array_values($groups), 'problem_subcategory_id');
    }

    /** @return list<array{month: string, total: int}> */
    public function monthly(TicketMetricsFilters $filters): array
    {
        $query = $this->query($filters);
        $expression = $query->getGrammar() instanceof SQLiteGrammar ? "strftime('%Y-%m', support_tickets.created_at)" : "DATE_FORMAT(support_tickets.created_at, '%Y-%m')";
        $counts = $query->selectRaw($expression.' AS month, COUNT(*) AS total')->groupByRaw($expression)->orderBy('month')->pluck('total', 'month');
        if ($filters->from === null || $filters->to === null) {
            return array_values($counts->map(fn ($total, $month) => ['month' => (string) $month, 'total' => (int) $total])->all());
        }
        $months = [];
        for ($month = $filters->from->startOfMonth(); $month <= $filters->to; $month = $month->addMonth()) {
            $key = $month->format('Y-m');
            $months[] = ['month' => $key, 'total' => (int) ($counts[$key] ?? 0)];
        }

        return $months;
    }

    /** @return LengthAwarePaginator<int, SupportTicket> */
    public function tickets(TicketMetricsFilters $filters, int $perPage): LengthAwarePaginator
    {
        $query = SupportTicket::with(['system', 'contact', 'customer', ...SupportTicket::CLASSIFICATION_RELATIONS]);
        $filters->apply($query->getQuery());

        return $query->orderByDesc('support_tickets.created_at')->orderByDesc('support_tickets.id')->paginate($perPage)->withQueryString();
    }

    private function subcategoryQuery(TicketMetricsFilters $filters): Builder
    {
        $query = $this->query($filters)->leftJoin(SupportTicket::SUBCATEGORY_PIVOT.' AS ticket_subcategory',
            'ticket_subcategory.support_ticket_id', '=', 'support_tickets.id');
        if ($filters->subcategoryId !== null) {
            $query->where('ticket_subcategory.problem_subcategory_id', $filters->subcategoryId);
        }

        return $query;
    }

    private function percentage(int $count, int $total): float
    {
        return $total === 0 ? 0.0 : round($count * 100 / $total, 2);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows, string $id): array
    {
        usort($rows, fn (array $a, array $b) => ($b['total'] <=> $a['total'])
            ?: (($a[$id] ?? PHP_INT_MAX) <=> ($b[$id] ?? PHP_INT_MAX))
            ?: (($a['problem_category_id'] ?? PHP_INT_MAX) <=> ($b['problem_category_id'] ?? PHP_INT_MAX)));

        return $rows;
    }
}
