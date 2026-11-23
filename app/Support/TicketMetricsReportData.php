<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Only explicitly projected statistical data may cross this boundary into document writers. */
final readonly class TicketMetricsReportData
{
    public const TITLE = 'Relatório Estatístico de Atendimentos de Suporte';

    /**
     * @param  list<array{label: string, value: string}>  $criteria
     * @param  array<string, int>  $summary
     * @param  list<array{name: string, total: int, closed: int, not_closed: int, percentage: float}>  $systems
     * @param  list<array{name: string, total: int, percentage: float, subcategories: list<array{name: string, total: int, percentage: float, systems: list<array{name: string, total: int, percentage: float}>}>}>  $categories
     * @param  list<array{month: string, total: int}>  $monthly
     */
    public function __construct(
        public CarbonImmutable $generatedAt,
        public array $criteria,
        public array $summary,
        public array $systems,
        public array $categories,
        public array $monthly,
    ) {}
}
