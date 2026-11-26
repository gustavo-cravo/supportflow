<?php

namespace Database\Seeders;

use App\Actions\SavePendingTask;
use App\Actions\SaveSupportTicket;
use App\Enums\PendingTaskStatus;
use App\Enums\TicketStatus;
use App\Enums\VehicleLine;
use App\Enums\VerificationSource;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Models\ProblemCategory;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Support\ProblemName;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * @phpstan-type Records array<string, list<int>>
 * @phpstan-type Result array{created: bool, from: string, to: string}
 */
final class SupportFlowCommercialDataset
{
    private const string PROFILE = 'SFT-COMERCIAL';

    private const int VERSION = 1;

    // SFT-VEIC owns ID 1. Its seeding, brand adoption and cleanup all scope to that ID.
    private const string BATCH_TABLE = 'vehicle_demo_batches';

    private const int BATCH_ID = 2;

    private const array RECORD_COUNTS = [
        'systems' => 3, 'customers' => 15, 'contacts' => 25, 'problem_categories' => 8,
        'problem_subcategories' => 24, 'vehicles' => 60, 'support_tickets' => 250,
        'pending_tasks' => 20, 'reminders' => 15, 'knowledge_articles' => 15,
    ];

    // Vehicle ticket positions mapped to the vehicle and category of the three guided cases.
    private const array GUIDED_CASES = [118 => [0, 0], 120 => [42, 1], 123 => [45, 2]];

    private const array GUIDED_RETURNS = [134 => 118, 141 => 120, 148 => 123];

    private const array STATUS_ROTATION = [
        TicketStatus::Closed, TicketStatus::Open, TicketStatus::Closed, TicketStatus::InProgress,
        TicketStatus::Waiting, TicketStatus::Closed, TicketStatus::Resolved, TicketStatus::Closed,
        TicketStatus::Open, TicketStatus::Closed, TicketStatus::InProgress, TicketStatus::Closed,
        TicketStatus::Waiting, TicketStatus::Closed, TicketStatus::Resolved, TicketStatus::Open,
        TicketStatus::Closed, TicketStatus::InProgress, TicketStatus::Waiting, TicketStatus::Closed,
    ];

    public function __construct(private SaveSupportTicket $saveTicket, private SavePendingTask $saveTask) {}

    /** @return Result */
    public function seed(): array
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('SFT-COMERCIAL só pode ser carregado em local ou testing.');
        }

        $connection = DB::connection();
        if (! $connection->getSchemaBuilder()->hasTable(self::BATCH_TABLE)) {
            throw new RuntimeException('SFT-COMERCIAL requer o esquema completo antes da carga.');
        }

        $clock = CarbonImmutable::now(TicketMetricsFilters::TIMEZONE)->startOfSecond();

        return $connection->transaction(function () use ($connection, $clock): array {
            $batch = $connection->table(self::BATCH_TABLE)->where('id', self::BATCH_ID);
            if ($batch->exists()) {
                return $this->existing($connection, $batch->lockForUpdate()->sole()->manifest);
            }

            $vehicles = SupportFlowCommercialCatalog::vehicles();
            $brands = $this->preflight(array_column($vehicles, 'plate'), array_unique(array_column($vehicles, 'brand')));
            if ($connection->table(self::BATCH_TABLE)->insertOrIgnore(['id' => self::BATCH_ID, 'manifest' => '{}']) !== 1) {
                return $this->existing($connection, $batch->lockForUpdate()->sole()->manifest);
            }

            [$records, $first, $last] = $this->populate($clock, $vehicles, $brands);
            $batch->update(['manifest' => json_encode([
                'profile' => self::PROFILE, 'version' => self::VERSION,
                'from' => $first, 'to' => $last, 'records' => $records,
            ], JSON_THROW_ON_ERROR)]);

            return ['created' => true, 'from' => $first, 'to' => $last];
        }, attempts: 3);
    }

    /** @return Result */
    private function existing(Connection $connection, string $json): array
    {
        $manifest = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['profile'] ?? null) !== self::PROFILE || ($manifest['version'] ?? null) !== self::VERSION
            || ! is_string($manifest['from'] ?? null) || ! is_string($manifest['to'] ?? null)) {
            throw new RuntimeException('O identificador de lote comercial está ocupado por um manifesto incompatível.');
        }

        foreach (self::RECORD_COUNTS as $table => $count) {
            $ids = $manifest['records'][$table] ?? null;
            if (! is_array($ids) || count($ids) !== $count
                || count(array_filter($ids, fn (mixed $id): bool => is_int($id) && $id > 0)) !== $count
                || $connection->table($table)->whereIntegerInRaw('id', $ids)->count() !== $count) {
                throw new RuntimeException('Lote SFT-COMERCIAL incompleto em '.$table.'. Os registros foram preservados.');
            }
        }

        return ['created' => false, 'from' => $manifest['from'], 'to' => $manifest['to']];
    }

    /**
     * @param  list<string>  $plates
     * @param  array<int, string>  $brandNames
     * @return array<string, int>
     */
    private function preflight(array $plates, array $brandNames): array
    {
        $systemNames = array_map(fn (string $name): string => self::PROFILE.' | '.$name, SupportFlowCommercialCatalog::SYSTEMS);
        $checks = [
            'systems' => ['name', $systemNames],
            'problem_categories' => ['normalized_name', array_map(ProblemName::key(...), array_column(SupportFlowCommercialCatalog::CLASSIFICATIONS, 'name'))],
            'vehicles' => ['plate', $plates],
            'contacts' => ['email', array_map(fn (int $index): string => $this->email('contato', $index), array_keys(SupportFlowCommercialCatalog::CONTACTS))],
            'customers' => ['email', array_map(fn (int $index): string => $this->email('cliente', $index), array_keys(SupportFlowCommercialCatalog::CUSTOMERS))],
        ];
        foreach ($checks as $table => [$column, $values]) {
            $conflict = DB::table($table)->whereIn($column, $values)->value($column);
            if ($conflict !== null) {
                throw new RuntimeException('Identificação comercial já usada em '.$table.': '.$conflict.'. Nenhum registro foi adotado.');
            }
        }

        $catalog = VehicleBrand::query()->whereIn('normalized_name', array_map(ProblemName::key(...), $brandNames))
            ->get(['id', 'name', 'normalized_name'])->keyBy('normalized_name');
        $brands = $missing = [];
        foreach ($brandNames as $name) {
            $brand = $catalog->get(ProblemName::key($name));
            if ($brand?->name === $name) {
                $brands[$name] = (int) $brand->id;
            } else {
                $missing[] = $name;
            }
        }
        if ($missing !== []) {
            throw new RuntimeException('Importe o catálogo permanente com VehicleBrandSeeder antes de SFT-COMERCIAL. Marcas pendentes: '.implode(', ', $missing).'.');
        }

        return $brands;
    }

    private function email(string $prefix, int $index): string
    {
        return sprintf('%s.%02d@sft-comercial.example.test', $prefix, $index + 1);
    }

    /**
     * @param  list<array{brand: string, plate: string, line: VehicleLine, model: ?string}>  $vehicleSpecs
     * @param  array<string, int>  $brandIds
     * @return array{Records, string, string}
     */
    private function populate(CarbonImmutable $clock, array $vehicleSpecs, array $brandIds): array
    {
        $records = [];
        $origin = $clock->startOfMonth()->subMonths(11);
        $registered = $origin->subDay()->setTime(9, 0);
        $systems = $customers = $contacts = $classifications = $vehicles = [];

        foreach (SupportFlowCommercialCatalog::SYSTEMS as $index => $name) {
            $systems[$index] = $this->record(new System([
                'name' => self::PROFILE.' | '.$name,
                'description' => 'Operação fictícia da demonstração comercial. Nenhuma indicação automotiva deste conjunto tem validade técnica.',
            ]), $registered, $records);
        }
        foreach (SupportFlowCommercialCatalog::CUSTOMERS as $index => $name) {
            $customers[$index] = $this->record(new Customer([
                'name' => $name, 'email' => $this->email('cliente', $index), 'notes' => 'Organização fictícia da demonstração comercial.',
            ]), $registered, $records);
        }
        foreach (SupportFlowCommercialCatalog::CONTACTS as $index => $name) {
            $contacts[$index] = $this->record(new Contact([
                'name' => $name.' Exemplo', 'email' => $this->email('contato', $index), 'notes' => 'Contato fictício da operação de suporte.',
            ]), $registered, $records);
        }
        foreach (SupportFlowCommercialCatalog::CLASSIFICATIONS as $index => $definition) {
            $category = $this->record(new ProblemCategory(['name' => $definition['name']]), $registered, $records);
            $available = array_map(fn (int $key): int => (int) $systems[$key]->getKey(), array_unique($definition['systems']));
            $category->systems()->attach($available, ['is_active' => true, 'created_at' => $registered, 'updated_at' => $registered]);
            $subcategoryIds = [];
            foreach ($definition['topics'] as [$name]) {
                $subcategory = $this->record($category->subcategories()->make(['name' => $name]), $registered, $records);
                $subcategory->systems()->attach($available, [
                    'is_active' => true, 'problem_category_id' => $category->id, 'created_at' => $registered, 'updated_at' => $registered,
                ]);
                $subcategoryIds[] = (int) $subcategory->id;
            }
            $classifications[$index] = ['category' => (int) $category->id, 'subcategories' => $subcategoryIds];
        }
        foreach ($vehicleSpecs as $index => $vehicle) {
            $vehicles[$index] = $this->record(new Vehicle([
                'brand_id' => $brandIds[$vehicle['brand']], 'plate' => $vehicle['plate'],
                'vehicle_line' => $vehicle['line'], 'model_description' => $vehicle['model'],
            ]), $registered, $records);
        }

        $tickets = $vehicleTickets = $categorySeen = $topicSeen = [];
        $slot = $vehicleSlot = 0;
        foreach (SupportFlowCommercialCatalog::MONTHLY_VOLUME as $month => $volume) {
            $start = $origin->addMonths($month);
            $days = $month === 11 ? $clock->day : $start->daysInMonth;
            for ($daySlot = 0; $daySlot < $volume; $daySlot++, $slot++) {
                // Spread openings through business hours on all available days of the month.
                $fraction = ($daySlot + 1) * $days - 1;
                $opened = $start->addDays(intdiv($fraction, $volume))->setTime(8, 30)
                    ->addMinutes(intdiv(($fraction % $volume) * 480, $volume))->min($clock);
                $position = $slot % 5 < 3 ? $vehicleSlot++ : null;
                $guided = self::GUIDED_CASES[$position] ?? null;
                $previousPosition = $position === null ? null : $this->previousPosition($position);
                $previous = $previousPosition === null ? null : $vehicleTickets[$previousPosition];
                $guidedReturn = $position !== null && isset(self::GUIDED_RETURNS[$position]);
                $vehicle = $guided[0] ?? $previous['vehicle'] ?? ($position === null ? null : $this->vehicleIndex($position));
                $category = $guided[1] ?? $previous['category'] ?? SupportFlowCommercialCatalog::CATEGORY_ROTATION[$slot % count(SupportFlowCommercialCatalog::CATEGORY_ROTATION)];
                $seen = $categorySeen[$category] = ($categorySeen[$category] ?? -1) + 1;
                $definition = SupportFlowCommercialCatalog::CLASSIFICATIONS[$category];
                $system = match (true) {
                    $guided !== null => $category === 2 ? 1 : 0,
                    $guidedReturn => $category === 2 ? 2 : 1,
                    default => $definition['systems'][$seen % count($definition['systems'])],
                };
                $topicIndex = $guided !== null || $guidedReturn ? 0 : $seen % 3;
                $topic = $definition['topics'][$topicIndex];
                $variant = $topicSeen[$category][$topicIndex] = ($topicSeen[$category][$topicIndex] ?? -1) + 1;
                $subcategories = [$classifications[$category]['subcategories'][$topicIndex]];
                $secondaryTopic = null;
                if ($slot % 6 === 0 || $guided !== null || $guidedReturn) {
                    $secondary = ($topicIndex + ($guidedReturn ? 2 : 1)) % 3;
                    $subcategories[] = $classifications[$category]['subcategories'][$secondary];
                    $secondaryTopic = $definition['topics'][$secondary][0];
                }
                $contact = ($vehicle ?? ($slot * 7 + intdiv($slot, 5))) % count($contacts);
                $context = SupportFlowCommercialCatalog::CONTEXTS[$variant % count(SupportFlowCommercialCatalog::CONTEXTS)];
                $title = ($previous === null ? '' : 'Retorno · ').$topic[1].' — '.$context;
                if ($variant >= count(SupportFlowCommercialCatalog::CONTEXTS)) {
                    $title .= ' · '.SupportFlowCommercialCatalog::CUSTOMERS[$contact % count($customers)];
                }
                $description = 'A ocorrência foi registrada no contexto de '.$context.'. '.$topic[2];
                if ($secondaryTopic !== null) {
                    $description .= ' A triagem registrou um segundo ponto para conferência ('.$secondaryTopic.').';
                }
                if ($previous !== null) {
                    $description .= "\n\nNovo contato após a ocorrência anterior para comparar as evidências e acompanhar a resposta da equipe. O retorno foi registrado com seu próprio histórico.";
                }
                $status = $guided !== null ? TicketStatus::Closed : self::STATUS_ROTATION[$slot % count(self::STATUS_ROTATION)];
                $activity = $opened->addHours($guided !== null ? 3 : 2)->min($clock);

                $attributes = [
                    'system_id' => $systems[$system]->getKey(), 'customer_id' => $customers[$contact % count($customers)]->getKey(),
                    'contact_id' => $contacts[$contact]->getKey(), 'vehicle_id' => $vehicle === null ? null : $vehicles[$vehicle]->getKey(),
                    'previous_ticket_id' => $previous === null ? null : $previous['ticket']->getKey(),
                    'problem_category_id' => $classifications[$category]['category'], 'problem_subcategory_ids' => $subcategories,
                    'title' => $title, 'description' => $description,
                ];
                $completed = in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true);
                $source = ($guided !== null ? $category : $slot) % 2 === 0 ? VerificationSource::ResponsibleDepartment : VerificationSource::Customer;
                $outcome = [
                    'status' => $status->value, 'resolution' => $completed ? $topic[3] : null,
                    'verification_source' => $completed ? $source->value : null,
                    'verified_at' => $completed ? ($guided !== null ? $opened->addMinutes(150) : $activity) : null,
                    'verified_by' => $completed ? ($source === VerificationSource::Customer ? $contacts[$contact]->name : 'Equipe de Curadoria') : null,
                    'verification_notes' => $completed ? $topic[4] : null,
                ];
                if ($guided !== null) {
                    $ticket = $this->saveTicket->execute(null, $attributes);
                    $ticket->forceFill(['created_at' => $opened, 'updated_at' => $opened])->save();
                    $this->guide($ticket, $opened, $topic[0], $records);
                    $ticket = $this->saveTicket->execute($ticket, $outcome);
                } else {
                    $ticket = $this->saveTicket->execute(null, [...$attributes, ...$outcome]);
                }
                $ticket->closed_at = $status === TicketStatus::Closed ? $activity : null;
                $this->record($ticket, $opened, $records, $activity);

                $tickets[] = $ticket;
                if ($position !== null) {
                    $vehicleTickets[$position] = ['ticket' => $ticket, 'vehicle' => $vehicle, 'category' => $category];
                }
            }
        }

        $this->activities($tickets, $clock, $records);
        $this->articles($systems, $registered, $records);

        return [$records, $tickets[0]->created_at->toDateString(), $tickets[array_key_last($tickets)]->created_at->toDateString()];
    }

    private function previousPosition(int $position): ?int
    {
        if (isset(self::GUIDED_RETURNS[$position])) {
            return self::GUIDED_RETURNS[$position];
        }

        return $position >= 64 && $position <= 112 && ($position - 64) % 3 === 0
            ? intdiv($position - 64, 3)
            : null;
    }

    private function vehicleIndex(int $position): int
    {
        if ($position < 60) {
            return $position;
        }

        // Keep a recurrent cohort, with additional occurrences spread through the fleet.
        return match ($position % 5) {
            0 => [0, 42, 45, 6][intdiv($position, 5) % 4],
            1, 2 => ($position * 7) % 36,
            default => ($position * 11) % 60,
        };
    }

    /** @param Records $records */
    private function guide(SupportTicket $ticket, CarbonImmutable $opened, string $topic, array &$records): void
    {
        $task = $this->saveTask->execute(null, [
            'support_ticket_id' => $ticket->getKey(), 'status' => PendingTaskStatus::Completed->value,
            'description' => 'Conciliar as evidências de '.$topic.' com a equipe responsável antes do retorno à operação.',
            'due_at' => $opened->addMinutes(100),
            'completion_notes' => 'Evidência reproduzida, revisão registrada e resultado disponível para validar no retorno.',
        ]);
        $task->completed_at = $opened->addMinutes(90);
        $this->record($task, $opened->addMinutes(10), $records, $opened->addMinutes(90));

        $this->record(new Reminder([
            'support_ticket_id' => $ticket->getKey(), 'title' => 'Retorno à operação para validar a correção',
            'description' => 'Repetir a consulta no cenário original com a operação e registrar o resultado antes do encerramento.',
            'scheduled_at' => $opened->addHours(2), 'completed_at' => $opened->addMinutes(130),
        ]), $opened->addMinutes(20), $records, $opened->addMinutes(130));
    }

    /**
     * @param  list<SupportTicket>  $tickets
     * @param  Records  $records
     */
    private function activities(array $tickets, CarbonImmutable $clock, array &$records): void
    {
        $created = $clock->subDays(4);
        $eligible = array_values(array_filter(array_reverse($tickets), fn (SupportTicket $ticket): bool => $ticket->created_at <= $created
            && $ticket->status !== TicketStatus::Closed->value));
        $taskDescriptions = [
            'Reunir as evidências da filial e confirmar o horário da consulta',
            'Comparar o resultado da busca com a revisão exibida na ficha',
            'Solicitar parecer da curadoria sobre a divergência registrada',
            'Conferir o vínculo da variante com a equipe de catálogo',
            'Consolidar o retorno do setor para repassar à operação',
            'Revisar o roteiro de triagem com os operadores',
        ];
        for ($index = 0; $index < 17; $index++) {
            $completed = $index >= 11 ? $clock->subDays(2) : null;
            $due = match (true) {
                $index < 4 => $clock->subDay(),
                $index < 8 => $clock->addDays(2),
                $completed !== null => $clock->subDay(),
                default => null,
            };
            $task = $this->saveTask->execute(null, [
                'support_ticket_id' => $index < 14 ? $eligible[$index]->getKey() : null,
                'description' => $taskDescriptions[$index % count($taskDescriptions)],
                'status' => ($completed === null ? PendingTaskStatus::Pending : PendingTaskStatus::Completed)->value,
                'due_at' => $due, 'completion_notes' => $completed === null ? null : 'Resultado registrado e repassado à operação para a próxima conferência.',
            ]);
            $task->completed_at = $completed;
            $this->record($task, $created, $records, $completed);
        }

        $reminderTitles = ['Revisar o resultado com a oficina', 'Conferir resposta da curadoria', 'Acompanhar a nova consulta'];
        for ($index = 0; $index < 12; $index++) {
            $scheduled = match (intdiv($index, 3)) {
                0 => $clock->subDay(),
                1 => $clock->setTime(9 + ($index % 3) * 3, 0),
                2 => $clock->addDays(2),
                default => $clock->subDays(2),
            };
            $completed = $index >= 9 ? $scheduled->addMinutes(20) : null;
            $this->record(new Reminder([
                'support_ticket_id' => $index < 9 ? $eligible[$index + 2]->getKey() : null,
                'title' => $reminderTitles[$index % 3],
                'description' => 'Retomar a evidência registrada e atualizar a operação sobre o próximo passo.',
                'scheduled_at' => $scheduled, 'completed_at' => $completed,
            ]), $created, $records, $completed);
        }
    }

    /**
     * @param  array<int, System>  $systems
     * @param  Records  $records
     */
    private function articles(array $systems, CarbonImmutable $registered, array &$records): void
    {
        $index = 0;
        foreach (SupportFlowCommercialCatalog::CLASSIFICATIONS as $category => $definition) {
            foreach (array_slice($definition['topics'], 0, $category === 7 ? 1 : 2) as $topic) {
                $this->record(new KnowledgeArticle([
                    'system_id' => $index % 5 === 4 ? null : $systems[$definition['systems'][0]]->getKey(),
                    'problem' => $topic[1],
                    'resolution_procedure' => "Sintoma observado\n".$topic[2]."\n\nTratamento no cenário fictício\n".$topic[3]."\n\nConferência\n".$topic[4],
                ]), $registered, $records);
                $index++;
            }
        }
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @param  Records  $records
     * @return T
     */
    private function record(Model $model, CarbonImmutable $created, array &$records, ?CarbonImmutable $updated = null): Model
    {
        $model->forceFill(['created_at' => $created, 'updated_at' => $updated ?? $created])->save();
        $records[$model->getTable()][] = (int) $model->getKey();

        return $model;
    }
}
