<?php

namespace App\Services\Reports;

use App\Models\SupportTicket;
use App\Support\AnalyticalTicketReportConfiguration;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * @phpstan-import-type PublishedTicket from AnalyticalTicketReportProjection
 *
 * @phpstan-type Document array{title: string, subtitle: ?string, generated_at: string, timezone: string, ticket_count: int, period: string, criteria: array<string, string>, grouping: string, layout: array{pagination: string, columns: string, spacing: string}, groups: list<array{label: ?string, tickets: list<PublishedTicket>}>}
 */
final class AnalyticalTicketReport
{
    public function __construct(private AnalyticalTicketReportProjection $projection) {}

    /** @return LengthAwarePaginator<int, SupportTicket> */
    public function eligible(TicketMetricsFilters $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = $this->projection->select($this->query($filters), [
            'title', 'system', 'problem_category', 'problem_subcategories', 'vehicle_brand', 'vehicle_model',
        ])->addSelect('support_tickets.created_at');

        return $this->order($query, 'newest')->paginate($perPage, page: $page)->withQueryString();
    }

    /** @return array{configuration: array<string, mixed>, title: string, eligible_count: int, selected_count: int} */
    public function validateConfiguration(TicketMetricsFilters $filters, AnalyticalTicketReportConfiguration $configuration): array
    {
        return DB::transaction(function () use ($filters, $configuration): array {
            [, $eligible, $selected] = $this->resolveSelection($filters, $configuration);
            $context = new AnalyticalTicketReportContext($filters, $configuration);

            return [
                'configuration' => $configuration->toArray(), 'title' => $context->title(),
                'eligible_count' => $eligible, 'selected_count' => $selected,
            ];
        });
    }

    /**
     * @return array{controls: array{filters: array<string, mixed>, configuration: array<string, mixed>}, document: Document}
     */
    public function prepare(TicketMetricsFilters $filters, AnalyticalTicketReportConfiguration $configuration, ?CarbonImmutable $generatedAt = null): array
    {
        if ($configuration->sections === []) {
            throw ValidationException::withMessages(['configuration.sections' => 'Selecione pelo menos um campo para o relatório.']);
        }

        return DB::transaction(function () use ($filters, $configuration, $generatedAt): array {
            [$query, , $total] = $this->resolveSelection($filters, $configuration);
            $this->projection->select($query, $configuration->fields(), $configuration->grouping === 'model');
            $groups = [];
            $published = 0;

            foreach ($this->order($query, $configuration->order)->lazy(200) as $ticket) {
                [$key, $label] = $this->projection->group($ticket, $configuration->grouping);
                $groups[$key] ??= ['label' => $label, 'tickets' => []];
                $groups[$key]['tickets'][] = $this->projection->ticket($ticket, $configuration);
                $published++;
            }

            if ($configuration->selection['mode'] === 'only' && $published !== $total) {
                throw ValidationException::withMessages([
                    'configuration.selection.ids' => 'Um ou mais atendimentos informados não estão disponíveis no recorte.',
                ]);
            }
            if ($published === 0) {
                throw ValidationException::withMessages([
                    'configuration.selection' => 'Selecione pelo menos um atendimento disponível para o relatório.',
                ]);
            }

            $timezone = TicketMetricsFilters::TIMEZONE;
            $generatedAt = ($generatedAt ?? CarbonImmutable::now($timezone))->setTimezone($timezone);
            $context = new AnalyticalTicketReportContext($filters, $configuration);

            return [
                'controls' => ['filters' => $filters->toArray(), 'configuration' => $configuration->toArray()],
                'document' => [
                    'title' => $context->title(), 'subtitle' => $configuration->subtitle,
                    'generated_at' => $generatedAt->toIso8601String(), 'timezone' => $timezone, 'ticket_count' => $published,
                    'period' => $context->period(), 'criteria' => $context->criteria(), 'grouping' => $configuration->grouping,
                    'layout' => $configuration->layout, 'groups' => array_values($groups),
                ],
            ];
        });
    }

    /** @return Builder<SupportTicket> */
    private function query(TicketMetricsFilters $filters): Builder
    {
        $query = SupportTicket::query();
        $filters->apply($query->getQuery());

        return $query;
    }

    /** @return array{Builder<SupportTicket>, int, int} */
    private function resolveSelection(TicketMetricsFilters $filters, AnalyticalTicketReportConfiguration $configuration): array
    {
        $query = $this->query($filters);
        $eligible = (clone $query)->count();
        $selected = $eligible;
        ['mode' => $mode, 'ids' => $ids] = $configuration->selection;
        if ($mode !== 'all') {
            $matching = (clone $query)->whereIntegerInRaw('support_tickets.id', $ids)->count();
            if ($matching !== count($ids)) {
                throw ValidationException::withMessages([
                    'configuration.selection.ids' => 'Um ou mais atendimentos informados não estão disponíveis no recorte.',
                ]);
            }
            if ($mode === 'only') {
                $query->whereIntegerInRaw('support_tickets.id', $ids);
                $selected = $matching;
            } else {
                $query->getQuery()->whereIntegerInRaw('support_tickets.id', $ids, not: true);
                $selected = $eligible - $matching;
            }
        }
        if ($selected === 0) {
            throw ValidationException::withMessages([
                'configuration.selection' => 'Selecione pelo menos um atendimento disponível para o relatório.',
            ]);
        }

        return [$query, $eligible, $selected];
    }

    /**
     * @param  Builder<SupportTicket>  $query
     * @return Builder<SupportTicket>
     */
    private function order(Builder $query, string $order): Builder
    {
        return match ($order) {
            'oldest' => $query->orderBy('support_tickets.created_at')->orderBy('support_tickets.id'),
            'title' => $query->orderByRaw('LOWER(support_tickets.title)')->orderBy('support_tickets.id'),
            default => $query->orderByDesc('support_tickets.created_at')->orderByDesc('support_tickets.id'),
        };
    }
}
