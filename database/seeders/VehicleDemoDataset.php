<?php

namespace Database\Seeders;

use App\Actions\SaveSupportTicket;
use App\Enums\TicketStatus;
use App\Enums\VehicleLine;
use App\Enums\VerificationSource;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\ProblemCategory;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use App\Support\ProblemName;
use App\Support\TicketMetricsFilters;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use stdClass;

/**
 * @phpstan-type Identity array<string, int|string|null>
 * @phpstan-type Manifest array{records: array<string, list<Identity>>, links: array<int, list<int>>, from: string, to: string}
 */
final class VehicleDemoDataset
{
    public const string SYSTEM_NAME = 'SFT-VEIC | Métricas veiculares fictícias';

    private const string MARKER = 'SFT-VEIC:metrics:v1 - Dados fictícios para validação.';

    private const string MANIFEST = 'vehicle_demo_batches';

    private const string CATEGORY = 'SFT-VEIC | Identificação veicular';

    private const string CONTACT = 'SFT-VEIC | Contato fictício';

    private const string CUSTOMER = 'SFT-VEIC | Oficina fictícia';

    private const array VEHICLES = [
        'TST1A01' => ['Volkswagen', VehicleLine::Light, 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007'],
        'TST1A02' => ['Volkswagen', VehicleLine::Light, 'SPACEFOX 1.6/ 1.6 Trend Total Flex 8V 5p 2007'],
        'TST2B01' => ['Volvo', VehicleLine::Heavy, 'FH 540 6x4 2022'],
        'TST2B02' => ['Scania', VehicleLine::Heavy, null],
    ];

    // Delete dependents before their parents. Only IDs in the manifest are candidates.
    private const array DEPENDENCIES = [
        'support_tickets' => ['pending_tasks' => 'support_ticket_id', 'reminders' => 'support_ticket_id', 'support_tickets' => 'previous_ticket_id'],
        'vehicles' => ['support_tickets' => 'vehicle_id'],
        'vehicle_brands' => ['vehicles' => 'brand_id'],
        'problem_subcategory_system' => [],
        'problem_category_system' => [],
        'problem_subcategories' => [SupportTicket::SUBCATEGORY_PIVOT => 'problem_subcategory_id', 'problem_subcategory_system' => 'problem_subcategory_id', 'support_tickets' => 'problem_subcategory_id'],
        'problem_categories' => ['problem_category_system' => 'problem_category_id', 'problem_subcategories' => 'problem_category_id', 'support_tickets' => 'problem_category_id'],
        'systems' => ['support_tickets' => 'system_id', 'knowledge_articles' => 'system_id', 'problem_category_system' => 'system_id'],
        'contacts' => ['support_tickets' => 'contact_id'],
        'customers' => ['support_tickets' => 'customer_id'],
    ];

    /** @return array{created: bool, system_id: int, from: string, to: string} */
    public function seed(): array
    {
        $this->ensureTestEnvironment();

        return DB::transaction(function (): array {
            $batch = DB::table(self::MANIFEST)->where('id', 1);
            $created = DB::table(self::MANIFEST)->insertOrIgnore(['id' => 1, 'manifest' => '{}']) === 1;
            if ($created) {
                $manifest = $this->populate();
                $batch->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
            } else {
                $manifest = $this->decode($batch->lockForUpdate()->sole()->manifest);
                $this->assertComplete($manifest);
            }

            return [
                'created' => $created, 'system_id' => (int) $manifest['records']['systems'][0]['id'],
                'from' => $manifest['from'], 'to' => $manifest['to'],
            ];
        }, attempts: 3);
    }

    /** @return array{removed: array<string, int>, retained: array<string, int>} */
    public function clean(): array
    {
        $this->ensureTestEnvironment();

        return DB::transaction(function (): array {
            $batch = DB::table(self::MANIFEST)->where('id', 1);
            $stored = $batch->lockForUpdate()->first();
            if ($stored === null) {
                return ['removed' => [], 'retained' => []];
            }
            $manifest = $this->decode($stored->manifest);
            $retained = $removed = [];
            foreach (self::DEPENDENCIES as $table => $references) {
                foreach ($manifest['records'][$table] ?? [] as $identity) {
                    $query = DB::table($table)->where('id', $identity['id']);
                    $row = $query->lockForUpdate()->first();
                    if ($row === null) {
                        continue;
                    }
                    if (! $this->matchesIdentity($row, $identity) || $this->inUse($table, $row, $references, $manifest['links'])) {
                        $retained[$table] = ($retained[$table] ?? 0) + 1;

                        continue;
                    }
                    $removed[$table] = ($removed[$table] ?? 0) + $query->delete();
                }
            }
            // Preserved records pass out of demo ownership after this cleanup.
            $batch->delete();

            return ['removed' => $removed, 'retained' => $retained];
        }, attempts: 3);
    }

    /** @param list<int> $brandIds */
    public function releaseVehicleBrands(array $brandIds): void
    {
        if (! Schema::hasTable(self::MANIFEST)) {
            return;
        }
        $batch = DB::table(self::MANIFEST)->where('id', 1);
        $stored = $batch->lockForUpdate()->first();
        if ($stored === null) {
            return;
        }
        $manifest = $this->decode($stored->manifest);
        $brands = $manifest['records']['vehicle_brands'] ?? [];
        $remaining = array_values(array_filter($brands, fn (array $identity) => ! in_array((int) $identity['id'], $brandIds, true)));
        if ($remaining === $brands) {
            return;
        }
        $manifest['records']['vehicle_brands'] = $remaining;
        $batch->update(['manifest' => json_encode($manifest, JSON_THROW_ON_ERROR)]);
    }

    private function ensureTestEnvironment(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('A demonstração veicular só pode ser administrada nos ambientes local ou testing.');
        }
    }

    /** @return Manifest */
    private function decode(string $json): array
    {
        $manifest = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ! isset($manifest['records'], $manifest['links'], $manifest['from'], $manifest['to'])) {
            throw new RuntimeException('O registro de propriedade da demonstração veicular está incompleto.');
        }

        return $manifest;
    }

    /** @param Manifest $manifest */
    private function assertComplete(array $manifest): void
    {
        $error = 'A base demonstrativa veicular está incompleta, compartilhada ou foi editada. Os registros existentes foram preservados.';
        if (count($manifest['records']['support_tickets'] ?? []) !== 8) {
            throw new RuntimeException($error);
        }
        foreach ($manifest['records'] as $table => $identities) {
            foreach ($identities as $identity) {
                $row = DB::table($table)->where('id', $identity['id'])->first();
                if ($row === null || ! $this->matchesIdentity($row, $identity)) {
                    throw new RuntimeException($error);
                }
            }
        }
        $systemId = $manifest['records']['systems'][0]['id'];
        if (SupportTicket::where('system_id', $systemId)->count() !== 8) {
            throw new RuntimeException($error);
        }
        foreach ($manifest['links'] as $ticketId => $subcategories) {
            if ($this->linkedSubcategories($ticketId) !== $subcategories) {
                throw new RuntimeException($error);
            }
        }
    }

    /** @return Manifest */
    private function populate(): array
    {
        $existing = Vehicle::whereIn('plate', array_keys(self::VEHICLES))->orderBy('plate')->pluck('plate');
        if ($existing->isNotEmpty()) {
            throw new RuntimeException('Placas de demonstração já usadas por registros não gerenciados: '.$existing->implode(', ').'.');
        }
        if (System::where('name', self::SYSTEM_NAME)->exists()
            || Contact::where('name', self::CONTACT)->exists() || Customer::where('name', self::CUSTOMER)->exists()
            || ProblemCategory::where('normalized_name', ProblemName::key(self::CATEGORY))->exists()) {
            throw new RuntimeException('Há cadastros auxiliares com a identificação reservada da demonstração veicular.');
        }
        $system = System::create(['name' => self::SYSTEM_NAME, 'description' => self::MARKER]);
        $contact = Contact::create(['name' => self::CONTACT, 'notes' => self::MARKER, 'email' => 'contato@sft-veic.example.test']);
        $customer = Customer::create(['name' => self::CUSTOMER, 'notes' => self::MARKER, 'document' => '00000000000500']);
        $category = ProblemCategory::create(['name' => self::CATEGORY, 'description' => self::MARKER]);
        $category->systems()->attach($system, ['is_active' => true]);
        $records = [
            'systems' => [$this->identity($system, 'name', 'description')],
            'contacts' => [$this->identity($contact, 'name', 'notes')],
            'customers' => [$this->identity($customer, 'name', 'notes')],
            'problem_categories' => [$this->identity($category, 'name', 'description')],
            'problem_category_system' => [get_object_vars(DB::table('problem_category_system')->where('system_id', $system->id)
                ->sole(['id', 'system_id', 'problem_category_id']))],
        ];
        $subcategories = [];
        foreach (['A' => 'Erro de identificação', 'B' => 'Informações técnicas incorretas'] as $code => $name) {
            $subcategory = $category->subcategories()->create(['name' => $name, 'description' => self::MARKER]);
            $subcategory->systems()->attach($system, ['is_active' => true, 'problem_category_id' => $category->id]);
            $records['problem_subcategories'][] = $this->identity($subcategory, 'name', 'description', 'problem_category_id');
            $records['problem_subcategory_system'][] = get_object_vars(DB::table('problem_subcategory_system')
                ->where('system_id', $system->id)->where('problem_subcategory_id', $subcategory->id)
                ->sole(['id', 'system_id', 'problem_category_id', 'problem_subcategory_id']));
            $subcategories[$code] = $subcategory->id;
        }
        $vehicles = [];
        foreach (self::VEHICLES as $plate => [$name, $line, $model]) {
            $brand = VehicleBrand::firstOrCreate(['normalized_name' => ProblemName::key($name)], ['name' => $name]);
            if ($brand->wasRecentlyCreated) {
                $records['vehicle_brands'][] = $this->identity($brand, 'normalized_name');
            }
            $vehicle = Vehicle::create(['plate' => $plate, 'brand_id' => $brand->id, 'vehicle_line' => $line, 'model_description' => $model]);
            $records['vehicles'][] = $this->identity($vehicle, 'plate', 'brand_id', 'vehicle_line', 'model_description');
            $vehicles[$plate] = $vehicle->id;
        }
        $today = CarbonImmutable::today(TicketMetricsFilters::TIMEZONE);
        $save = new SaveSupportTicket;
        $links = [];
        foreach ([
            ['TST1A01', ['A', 'B'], true], ['TST1A01', ['A'], false], ['TST1A02', ['B'], false],
            ['TST2B01', ['A', 'B'], true], ['TST2B01', ['A'], true], ['TST2B02', ['B'], false],
            [null, ['A'], false], [null, [], true],
        ] as $index => [$plate, $codes, $closed]) {
            $opened = $today->subDays(8 - $index)->setTime(10, 0);
            $activity = $opened->addHours($closed ? 2 : 0);
            $ids = array_map(fn (string $code) => $subcategories[$code], $codes);
            $vehicleId = $plate === null ? null : ($vehicles[$plate] ?? throw new RuntimeException('Placa ausente na configuração da demonstração: '.$plate));
            $ticket = $save->execute(null, [
                'system_id' => $system->id, 'contact_id' => $contact->id, 'customer_id' => $customer->id,
                'vehicle_id' => $vehicleId, 'problem_category_id' => $category->id,
                'problem_subcategory_ids' => $ids, 'title' => sprintf('SFT-VEIC ATE-%03d | Validação de métricas', $index + 1),
                'description' => self::MARKER, 'status' => ($closed ? TicketStatus::Closed : TicketStatus::Open)->value,
                'resolution' => $closed ? 'Conferência fictícia concluída.' : null,
                'verification_source' => $closed ? VerificationSource::ResponsibleDepartment->value : null,
                'verified_at' => $closed ? $activity : null,
            ]);
            $ticket->forceFill(['created_at' => $opened, 'updated_at' => $activity, 'closed_at' => $closed ? $activity : null])->save();
            $records['support_tickets'][] = $this->identity($ticket, 'title', 'description', 'system_id', 'vehicle_id',
                'contact_id', 'customer_id', 'previous_ticket_id', 'problem_category_id', 'problem_subcategory_id', 'status', 'created_at');
            $links[$ticket->id] = $ids;
        }

        return ['records' => $records, 'links' => $links,
            'from' => $today->subDays(8)->toDateString(), 'to' => $today->subDay()->toDateString()];
    }

    /** @return Identity */
    private function identity(Model $record, string ...$fields): array
    {
        $keys = array_fill_keys(['id', ...$fields], null);

        return Arr::only($record->getRawOriginal(), array_keys($keys)) + $keys;
    }

    /** @param Identity $identity */
    private function matchesIdentity(stdClass $row, array $identity): bool
    {
        foreach ($identity as $attribute => $expected) {
            $actual = $row->{$attribute};
            if (($expected === null) !== ($actual === null) || (string) $actual !== (string) $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, string>  $references
     * @param  array<int, list<int>>  $links
     */
    private function inUse(string $table, stdClass $row, array $references, array $links): bool
    {
        foreach ($references as $source => $column) {
            if (DB::table($source)->where($column, $row->id)->lockForUpdate()->first([$column]) !== null) {
                return true;
            }
        }
        if ($table === 'support_tickets') {
            return array_diff($this->linkedSubcategories((int) $row->id), $links[$row->id] ?? []) !== [];
        }
        if (in_array($table, ['problem_category_system', 'problem_subcategory_system'], true)) {
            return $this->classificationUsed($table, $row);
        }

        return false;
    }

    private function classificationUsed(string $table, stdClass $row): bool
    {
        $tickets = DB::table('support_tickets')->where('system_id', $row->system_id)->where('problem_category_id', $row->problem_category_id);
        if ($table === 'problem_category_system') {
            return $tickets->lockForUpdate()->first(['id']) !== null || DB::table('problem_subcategory_system')
                ->where('system_id', $row->system_id)->where('problem_category_id', $row->problem_category_id)
                ->lockForUpdate()->first(['id']) !== null;
        }

        return $tickets->where(function (Builder $query) use ($row): void {
            $query->where('problem_subcategory_id', $row->problem_subcategory_id)
                ->orWhereIn('id', DB::table(SupportTicket::SUBCATEGORY_PIVOT)->select('support_ticket_id')
                    ->where('problem_subcategory_id', $row->problem_subcategory_id));
        })->lockForUpdate()->first(['id']) !== null;
    }

    /** @return list<int> */
    private function linkedSubcategories(int $ticketId): array
    {
        return array_values(DB::table(SupportTicket::SUBCATEGORY_PIVOT)->where('support_ticket_id', $ticketId)
            ->orderBy('problem_subcategory_id')->lockForUpdate()->pluck('problem_subcategory_id')->map(fn ($id): int => (int) $id)->all());
    }
}
