<?php

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use App\Models\Vehicle;
use App\Models\VehicleBrand;
use Carbon\CarbonImmutable;

final class AnalyticalTicketPdfFixture
{
    public readonly System $system;

    public readonly System $otherSystem;

    public readonly ProblemCategory $category;

    public readonly ProblemCategory $otherCategory;

    public readonly Vehicle $ford;

    public readonly Vehicle $volvo;

    public readonly Vehicle $missingModel;

    public readonly SupportTicket $previous;

    public readonly ProblemSubcategory $problem;

    public readonly ProblemSubcategory $otherProblem;

    private readonly Contact $contact;

    private readonly Customer $customer;

    private int $number = 0;

    public function __construct()
    {
        $this->system = System::create(['name' => 'ArgoLub']);
        $this->otherSystem = System::create(['name' => 'Indicar']);
        $this->contact = Contact::create(['name' => 'Contato de demonstração']);
        $this->customer = Customer::create(['name' => 'Cliente de demonstração']);
        $this->category = ProblemCategory::create(['name' => 'Erro de indicação']);
        $this->otherCategory = ProblemCategory::create(['name' => 'Consulta de aplicação']);
        $this->problem = ProblemSubcategory::create(['name' => 'Filtro de óleo incorreto', 'problem_category_id' => $this->category->id]);
        $this->otherProblem = ProblemSubcategory::create(['name' => 'Referência divergente', 'problem_category_id' => $this->category->id]);
        $ford = VehicleBrand::create(['name' => 'Ford']);
        $volvo = VehicleBrand::create(['name' => 'Volvo']);
        $this->ford = Vehicle::factory()->for($ford, 'brand')->create([
            'plate' => 'ZQA1234', 'vehicle_line' => 'light', 'model_description' => 'Série LX 2007',
        ]);
        $this->volvo = Vehicle::factory()->for($volvo, 'brand')->create([
            'plate' => 'ZQA2345', 'vehicle_line' => 'heavy', 'model_description' => 'Série LX 2007',
        ]);
        $this->missingModel = Vehicle::factory()->for($ford, 'brand')->create([
            'plate' => 'ZQA3456', 'vehicle_line' => 'light', 'model_description' => null,
        ]);
        $this->previous = $this->ticket('Conferência anterior de catálogo', [
            'created_at' => '2026-10-20 11:45:00',
            'description' => 'CONTEÚDO EXCLUSIVO DA REFERÊNCIA ANTERIOR',
            'previous_ticket_id' => null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function ticket(string $title, array $attributes = []): SupportTicket
    {
        $this->number++;
        $row = [
            'title' => $title, 'system_id' => $this->system->id, 'contact_id' => $this->contact->id,
            'customer_id' => $this->customer->id, 'previous_ticket_id' => $this->previous->id ?? null,
            'vehicle_id' => $this->ford->id, 'problem_category_id' => $this->category->id, 'status' => 'closed',
            'created_at' => CarbonImmutable::parse('2026-11-11 08:00:00', 'America/Sao_Paulo')->addHours($this->number),
            'closed_at' => '2026-11-20 17:50:00',
            'description' => "O catálogo indicava uma aplicação diferente para o componente informado.\nA equipe conferiu a referência com a documentação técnica.",
            'resolution' => 'A referência foi atualizada e a aplicação correta foi revisada no catálogo.',
            'verification_source' => 'responsible_department', 'verified_at' => '2026-11-20 16:41:23',
            'verified_by' => 'Equipe de validação', 'verification_notes' => 'O setor responsável repetiu a consulta e confirmou a aplicação revisada.',
            ...$attributes,
        ];
        $ticket = SupportTicket::unguarded(static fn () => SupportTicket::create($row));
        if ($row['problem_category_id'] === $this->category->id) {
            $ticket->problemSubcategories()->attach([$this->problem->id, $this->otherProblem->id], ['problem_category_id' => $this->category->id]);
        }

        return $ticket;
    }

    /** @param array<string, list<string>> $fields */
    public static function fields(array $fields): array
    {
        return array_map(static fn (array $selection): array => ['enabled' => true, 'fields' => $selection], $fields);
    }

    public static function narrative(string $prefix, int $paragraphs): string
    {
        $parts = [];
        for ($index = 1; $index <= $paragraphs; $index++) {
            $parts[] = $prefix.' '.str_pad((string) $index, 3, '0', STR_PAD_LEFT)." — Conferência de aplicação, pressão e referência nº 25.\n"
                .'A equipe conferiu a configuração do catálogo, suas variantes e os parâmetros do componente. A comparação preserva a descrição original do registro.';
        }

        return implode("\n\n", $parts);
    }
}
