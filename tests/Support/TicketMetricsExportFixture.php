<?php

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Customer;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Support\Collection;

final readonly class TicketMetricsExportFixture
{
    /** @var Collection<int, System> */
    public Collection $systems;

    public ProblemCategory $category;

    public ProblemSubcategory $login;

    public ProblemSubcategory $credentials;

    public ProblemSubcategory $plate;

    public function __construct()
    {
        $this->systems = collect(['Indicar', 'iLube', '.way'])->map(fn ($name) => System::create(['name' => $name]));
        $contact = Contact::create(['name' => 'CONTATO_PRIVADO_7C52', 'phone' => '5511987654321', 'email' => 'privado7c52@example.test', 'notes' => 'OBSERVACAO_PRIVADA_7C52']);
        $customer = Customer::create(['name' => 'CLIENTE_PRIVADO_7C52', 'document' => '01234567000189']);
        $this->category = ProblemCategory::create(['name' => 'Falha de acesso']);
        $other = ProblemCategory::create(['name' => 'Busca por placa']);
        $this->category->systems()->attach($this->systems->pluck('id'), ['is_active' => true]);
        $other->systems()->attach($this->systems[0]->id, ['is_active' => true]);
        $this->login = ProblemSubcategory::create(['problem_category_id' => $this->category->id, 'name' => 'Sessão expirada']);
        $this->credentials = ProblemSubcategory::create(['problem_category_id' => $this->category->id, 'name' => 'Credenciais inválidas']);
        $this->plate = ProblemSubcategory::create(['problem_category_id' => $other->id, 'name' => 'Placa não encontrada']);
        foreach ([$this->login, $this->credentials] as $subcategory) {
            $subcategory->systems()->attach($this->systems->pluck('id'), ['is_active' => true, 'problem_category_id' => $this->category->id]);
        }
        $this->plate->systems()->attach($this->systems[0]->id, ['is_active' => true, 'problem_category_id' => $other->id]);
        $rows = [
            [0, $this->category->id, $this->login->id, 'open', '2026-11-01 00:00:00'],
            [0, $this->category->id, $this->login->id, 'closed', '2026-11-23 23:59:59'],
            [1, $this->category->id, $this->credentials->id, 'resolved', '2026-10-15 10:00:00'],
            [1, $this->category->id, null, 'waiting', '2026-08-10 10:00:00'],
            [2, null, null, 'open', '2026-11-15 10:00:00'],
            [0, $other->id, $this->plate->id, 'closed', '2026-05-31 23:59:59'],
            [0, $other->id, $this->plate->id, 'in_progress', '2026-11-24 00:00:00'],
            [0, null, null, 'closed', '2026-06-01 00:00:00'],
        ];
        foreach ($rows as $row) {
            $ticket = SupportTicket::make(['system_id' => $this->systems[$row[0]]->id, 'contact_id' => $contact->id, 'customer_id' => $customer->id,
                'problem_category_id' => $row[1], 'problem_subcategory_id' => $row[2], 'status' => $row[3],
                'title' => 'TITULO_INDIVIDUAL_7C52', 'description' => 'DESCRICAO_INDIVIDUAL_7C52', 'resolution' => 'RESOLUCAO_PRIVADA_7C52',
            ])->forceFill(['created_at' => $row[4], 'updated_at' => $row[4]]);
            $ticket->save();
            if ($row[2] !== null) {
                $ticket->problemSubcategories()->attach($row[2], ['problem_category_id' => $row[1]]);
            }
        }
        $this->systems[1]->update(['is_active' => false]);
        $this->category->update(['is_active' => false]);
        $this->login->update(['is_active' => false]);
        $this->category->systems()->updateExistingPivot($this->systems[0]->id, ['is_active' => false]);
    }
}
