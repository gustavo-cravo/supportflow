<?php

namespace Database\Seeders;

use App\Enums\TicketStatus;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Reference calculations concern only the small fixture set, never the application totals. */
final class SupportFlowValidationReference
{
    /** @return array<string, mixed> */
    public function build(): array
    {
        $today = CarbonImmutable::now(TicketMetricsFilters::TIMEZONE)->startOfDay();
        $tickets = SupportTicket::with(['system', 'problemCategory', 'problemSubcategory'])
            ->whereLike('title', SupportFlowValidationSeeder::PREFIX.' ATE-% | %')->orderByDesc('created_at')->orderBy('title')->get();
        $ids = array_values($tickets->map(fn (SupportTicket $ticket): int => $ticket->id)->all());
        $counts = [];
        foreach (SupportFlowValidationSeeder::entities() as $class => $definition) {
            $counts[(new $class)->getTable()] = $class::whereLike($definition['column'], SupportFlowValidationSeeder::PREFIX.' '.$definition['code'].'-% | %')->count();
        }
        $counts['problem_category_system'] = DB::table('problem_category_system')->whereIn('problem_category_id', ProblemCategory::whereLike('name', 'SFT-QA CAT-% | %')->select('id'))->count();
        $counts['problem_subcategory_system'] = DB::table('problem_subcategory_system')->whereIn('problem_subcategory_id', ProblemSubcategory::whereLike('name', 'SFT-QA SUB-% | %')->select('id'))->count();
        $systems = $tickets->groupBy('system_id')->map(fn ($group) => [
            'name' => $group->first()->system->name, 'total' => $group->count(),
            'closed' => $group->where('status', TicketStatus::Closed->value)->count(),
        ])->sortByDesc('total')->values()->all();
        $categories = $tickets->groupBy(fn ($row) => $row->problem_category_id ? $row->problemCategory->name : 'Sem classificação')->map(fn ($group, $name) => ['name' => $name, 'total' => $group->count()])->sortByDesc('total')->values()->all();
        $subcategories = $tickets->groupBy(fn ($row) => ($row->problem_category_id ? $row->problemCategory->name : 'Sem classificação').' / '.($row->problem_subcategory_id ? $row->problemSubcategory->name : ($row->problem_category_id ? 'Sem subcategoria' : 'Sem classificação')))
            ->map(fn ($group, $name) => ['name' => $name, 'total' => $group->count()])->sortByDesc('total')->values()->all();
        $ageBuckets = [];
        foreach (['Hoje' => [0, 0], 'Ontem' => [1, 1], '2 a 7 dias' => [2, 7], '8 a 15 dias' => [8, 15], '16 a 30 dias' => [16, 30], '31 a 60 dias' => [31, 60], '61 a 90 dias' => [61, 90], 'Mais de 90 dias' => [91, PHP_INT_MAX]] as $label => [$from, $to]) {
            $ageBuckets[$label] = $tickets->filter(function ($ticket) use ($today, $from, $to): bool {
                $age = $ticket->created_at->startOfDay()->diffInDays($today);

                return $age >= $from && $age <= $to;
            })->count();
        }
        $filters = [];
        foreach (['all' => 'Todo o histórico', 'this_month' => 'Este mês', 'last_30_days' => 'Últimos 30 dias', 'last_3_months' => 'Últimos 3 meses', 'last_6_months' => 'Últimos 6 meses', 'this_year' => 'Este ano'] as $period => $label) {
            $filters[] = ['label' => $label, 'params' => ['period' => $period], ...$this->filtered($ids, ['period' => $period])];
        }
        $system = System::whereLike('name', 'SFT-QA SIS-001 | %')->sole();
        $category = ProblemCategory::whereLike('name', 'SFT-QA CAT-001 | %')->sole();
        $subcategory = ProblemSubcategory::whereLike('name', 'SFT-QA SUB-001 | %')->sole();
        foreach ([
            'Indicar / histórico' => ['period' => 'all', 'system_id' => $system->id],
            'Falha de acesso / histórico' => ['period' => 'all', 'problem_category_id' => $category->id],
            'Sessão expirada / histórico' => ['period' => 'all', 'problem_subcategory_id' => $subcategory->id],
            'Indicar + acesso + sessão / histórico' => ['period' => 'all', 'system_id' => $system->id, 'problem_category_id' => $category->id, 'problem_subcategory_id' => $subcategory->id],
            'Sem classificação / histórico' => ['period' => 'all', 'classification' => 'unclassified'],
            'Sem subcategoria / histórico' => ['period' => 'all', 'classification' => 'without_subcategory'],
            'Últimos 7 dias (hoje e seis anteriores)' => ['period' => 'custom', 'date_from' => $today->subDays(6)->toDateString(), 'date_to' => $today->toDateString()],
        ] as $label => $params) {
            $filters[] = ['label' => $label, 'params' => $params, ...$this->filtered($ids, $params)];
        }

        return [
            'reference_date' => $today->toDateString(), 'timezone' => TicketMetricsFilters::TIMEZONE,
            'counts' => $counts, 'summary' => $this->filtered($ids, ['period' => 'all']),
            'systems' => $systems, 'categories' => $categories, 'subcategories' => $subcategories,
            'statuses' => $tickets->groupBy('status')->map->count()->all(), 'age_buckets' => $ageBuckets,
            'monthly' => $tickets->groupBy(fn ($ticket) => $ticket->created_at->format('Y-m'))->map->count()->sortKeys()->all(),
            'dates' => $tickets->map(fn ($ticket) => ['code' => explode(' | ', $ticket->title)[0], 'opened_at' => $ticket->created_at->format('Y-m-d H:i:s'), 'status' => $ticket->status])->all(),
            'filters' => $filters,
        ];
    }

    /** @param list<int> $ids
     * @param  array<string, mixed>  $params
     * @return array{total: int, closed: int, not_closed: int, unclassified: int}
     */
    private function filtered(array $ids, array $params): array
    {
        $rows = TicketMetricsFilters::fromValidated($params)->apply(DB::table('support_tickets')->whereIn('support_tickets.id', $ids))->get(['status', 'problem_category_id']);
        $closed = $rows->where('status', TicketStatus::Closed->value)->count();

        return ['total' => $rows->count(), 'closed' => $closed, 'not_closed' => $rows->count() - $closed, 'unclassified' => $rows->whereNull('problem_category_id')->count()];
    }

    public function print(?Command $command): void
    {
        if ($command === null) {
            return;
        }
        $data = $this->build();
        $command->comment('Referência exclusiva SFT-QA em '.$data['reference_date'].' / '.$data['timezone'].'. Outros registros também contribuem para os totais do Dashboard.');
        $command->table(['Tabela', 'Registros SFT-QA'], $this->pairs($data['counts']));
        foreach (['systems' => 'Sistemas', 'categories' => 'Categorias', 'subcategories' => 'Subcategorias'] as $key => $title) {
            $command->comment($title);
            $command->table(['Nome', 'Atendimentos'], array_map(fn ($row) => [$row['name'], $row['total']], $data[$key]));
        }
        $command->table(['Status', 'Quantidade'], $this->pairs($data['statuses']));
        $command->table(['Idade em dias de calendário', 'Quantidade'], $this->pairs($data['age_buckets']));
        $command->table(['Mês de abertura', 'Quantidade'], $this->pairs($data['monthly']));
        $command->table(['Filtro', 'Total', 'Encerrados', 'Não encerrados', 'Sem classificação'], array_map(fn ($row) => [$row['label'], $row['total'], $row['closed'], $row['not_closed'], $row['unclassified']], $data['filters']));
        $command->table(['Código fictício', 'Abertura local', 'Status'], array_map('array_values', $data['dates']));
    }

    /** @param array<string, int> $values
     * @return list<array{string, int}>
     */
    private function pairs(array $values): array
    {
        $rows = [];
        foreach ($values as $label => $count) {
            $rows[] = [$label, $count];
        }

        return $rows;
    }
}
