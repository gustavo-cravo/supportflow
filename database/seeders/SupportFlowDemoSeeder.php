<?php

namespace Database\Seeders;

use App\Enums\PendingTaskStatus;
use App\Enums\TicketStatus;
use App\Enums\VerificationSource;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Models\PendingTask;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use Carbon\CarbonImmutable;
use Faker\Factory;
use Faker\Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupportFlowDemoSeeder extends Seeder
{
    public const string PREFIX = '[DEMO SF]';

    private CarbonImmutable $clock;

    private Generator $faker;

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('SupportFlowDemoSeeder só pode ser executado nos ambientes local ou testing.');
        }

        $this->clock = CarbonImmutable::now('America/Sao_Paulo')->startOfSecond();
        $this->faker = Factory::create('pt_BR');
        $this->faker->seed(20261021);

        DB::transaction(function (): void {
            $systems = $this->systems();
            $contacts = $this->contacts();
            $customers = $this->customers();
            $tickets = $this->tickets($systems, $contacts, $customers);
            $this->pendingTasks($tickets);
            $this->reminders($tickets);
            $this->articles($systems);

            // Historical tickets exist before their systems are deactivated.
            foreach (array_slice($systems, 6) as $system) {
                $system->update(['is_active' => false]);
            }
        });

        $this->command->info('Demonstração restaurada: 8 sistemas, 26 contatos, 20 clientes, 90 atendimentos (15 retornos), 48 pendências, 48 lembretes e 20 artigos.');
    }

    /** @return list<System> */
    private function systems(): array
    {
        $systems = [];
        foreach (SupportFlowDemoCatalog::systems() as $index => $definition) {
            $systems[] = $this->record(System::class, 'name', sprintf('SIS-%03d', $index + 1), $definition['name'], [
                'description' => $definition['description'], 'is_active' => true,
                'created_at' => $this->clock->subDays(500),
                'updated_at' => $this->clock->subDays($index >= 6 ? 60 : 20),
            ]);
        }

        return $systems;
    }

    /** @return list<Contact> */
    private function contacts(): array
    {
        $contacts = [];
        $roles = ['Responsável pelo balcão', 'Operador de consultas', 'Analista do setor fiscal', 'Consultor de implantação', 'Responsável pelo estoque'];
        for ($index = 1; $index <= 26; $index++) {
            $contacts[] = $this->record(Contact::class, 'name', sprintf('CON-%03d', $index), $this->faker->name(), [
                'phone' => $index % 5 === 0 ? null : $this->faker->phoneNumber(),
                'email' => $index % 7 === 0 ? null : sprintf('contato.%02d@supportflow.example.test', $index),
                'notes' => $roles[($index - 1) % count($roles)].'. Pessoa fictícia para demonstração.',
            ]);
        }

        return $contacts;
    }

    /** @return list<Customer> */
    private function customers(): array
    {
        $customers = [];
        for ($index = 1; $index <= 20; $index++) {
            $customers[] = $this->record(Customer::class, 'name', sprintf('CLI-%03d', $index), $this->faker->company(), [
                'phone' => $index % 4 === 0 ? null : $this->faker->phoneNumber(),
                'email' => $index % 6 === 0 ? null : sprintf('empresa.%02d@supportflow.example.test', $index),
                'notes' => 'Empresa fictícia. Unidade de '.$this->faker->city().'; atendimento a oficinas, lojas e serviços automotivos.',
            ]);
        }

        return $customers;
    }

    /** @param list<System> $systems
     * @param  list<Contact>  $contacts
     * @param  list<Customer>  $customers
     * @return list<SupportTicket>
     */
    private function tickets(array $systems, array $contacts, array $customers): array
    {
        $tickets = [];
        $definitions = SupportFlowDemoCatalog::systems();
        $distribution = [0, 0, 0, 1, 1, 2, 2, 3, 4, 5, 6, 7];
        $statuses = [TicketStatus::Open, TicketStatus::Open, TicketStatus::InProgress, TicketStatus::Waiting, TicketStatus::Waiting, TicketStatus::Resolved, TicketStatus::Closed, TicketStatus::Closed, TicketStatus::InProgress, TicketStatus::Open];
        for ($index = 0; $index < 75; $index++) {
            $systemIndex = $distribution[$index % count($distribution)];
            $issue = $definitions[$systemIndex]['issues'][intdiv($index, 12) % 2];
            $contact = $contacts[$index % count($contacts)];
            $customer = $index < 2 ? $customers[0] : ($index % 7 === 0 ? null : $customers[$index % count($customers)]);
            $status = $systemIndex >= 6 || $index === 0
                ? TicketStatus::Closed
                : ($index === 1 ? TicketStatus::Waiting : $statuses[$index % count($statuses)]);
            $days = $systemIndex >= 6 ? 240 + $index : ($index < 60 ? 180 - $index * 2 : 74 - $index);
            $created = $this->clock->subDays($days)->subHours($index % 8 + 1);

            $tickets[] = $this->record(SupportTicket::class, 'title', sprintf('ATE-%03d', $index + 1), $issue['title'], [
                'system_id' => $systems[$systemIndex]->id, 'contact_id' => $contact->id,
                'customer_id' => $customer?->id, 'previous_ticket_id' => null,
                'description' => $issue['description']."\nSolicitação de ".$contact->name.'. '.($customer ? 'Empresa: '.$customer->name.'.' : 'Atendimento interno, sem cliente associado.')
                    .($status === TicketStatus::Waiting ? "\nAguardando retorno do setor responsável ou do consultor com as evidências solicitadas." : ''),
                ...$this->ticketState($status, $created, $issue['resolution'], $contact->name, $index),
            ]);
        }

        // Only active systems receive recent follow-ups. These indices refer to
        // the generated collection, never to primary keys in the database.
        $parents = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 12, 13, 14, 15, 16];
        foreach ($parents as $index => $parentIndex) {
            $parent = $tickets[$parentIndex];
            $status = TicketStatus::cases()[$index % count(TicketStatus::cases())];
            $created = $this->clock->subDays($index % 6)->subHours($index % 4 + 1);
            $title = explode(' | ', $parent->title, 2)[1];
            $tickets[] = $this->record(SupportTicket::class, 'title', sprintf('ATE-%03d', 76 + $index), 'Retorno: '.$title, [
                'system_id' => $parent->system_id, 'contact_id' => $parent->contact_id,
                'customer_id' => $parent->customer_id, 'previous_ticket_id' => $parent->id,
                'description' => 'O mesmo contato voltou a relatar o problema de '.$parent->title.'. A análise deste retorno é independente do atendimento anterior.'
                    ."\nContexto anterior: ".$parent->description
                    .($status === TicketStatus::Waiting ? "\nO consultor ficou de confirmar o mapeamento com o setor responsável." : ''),
                ...$this->ticketState($status, $created, 'Aplicada a revisão complementar do procedimento anterior; o fluxo foi repetido com o contato e o resultado documentado.', $contacts[$parentIndex % count($contacts)]->name, $index),
            ]);
        }

        return $tickets;
    }

    /** @return array<string, mixed> */
    private function ticketState(TicketStatus $status, CarbonImmutable $created, string $resolution, string $contact, int $index): array
    {
        $finished = in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true);
        $activity = $created->addDays($finished ? 2 : 7)->min($this->clock);
        $source = $index % 2 === 0 ? VerificationSource::Customer : VerificationSource::ResponsibleDepartment;

        return [
            'status' => $status->value, 'created_at' => $created, 'updated_at' => $activity,
            'closed_at' => $status === TicketStatus::Closed ? $activity : null,
            'resolution' => $finished ? $resolution : null,
            'verification_source' => $finished ? $source->value : null,
            'verified_at' => $finished ? $activity : null,
            'verified_by' => $finished ? ($source === VerificationSource::Customer ? $contact : 'Setor responsável / consultoria de demonstração') : null,
            'verification_notes' => $finished ? 'Resultado conferido em cenário fictício; o contato ou setor responsável confirmou a execução do procedimento e a consulta dos registros.' : null,
        ];
    }

    /** @param list<SupportTicket> $tickets */
    private function pendingTasks(array $tickets): void
    {
        $descriptions = ['Solicitar evidência atualizada do problema', 'Aguardar retorno do setor responsável', 'Validar o procedimento com o contato', 'Revisar parâmetros da integração', 'Confirmar comportamento após sincronização', 'Acompanhar análise do consultor', 'Documentar resultado dos testes', 'Revisar prevenção de reincidência'];
        for ($index = 0; $index < 48; $index++) {
            $completed = $index % 4 === 3;
            $due = match ($index % 4) {
                0 => $this->clock->startOfDay()->subDays(2)->addHours(11),
                1 => $this->clock->startOfDay()->addDays(2)->addHours(14),
                2 => null,
                3 => $this->clock->subDays(3),
            };
            $this->record(PendingTask::class, 'description', sprintf('PEN-%03d', $index + 1), $descriptions[$index % count($descriptions)], [
                'support_ticket_id' => $index < 36 ? $tickets[$index]->id : null,
                'due_at' => $due, 'status' => $completed ? PendingTaskStatus::Completed->value : PendingTaskStatus::Pending->value,
                'completed_at' => $completed ? $this->clock->subDay() : null,
                'completion_notes' => $completed ? 'Evidências recebidas, procedimento conferido e resultado registrado no acompanhamento de demonstração.' : null,
                'created_at' => $this->clock->subDays(10),
                'updated_at' => $completed ? $this->clock->subDay() : $this->clock->subHours(6),
            ]);
        }
    }

    /** @param list<SupportTicket> $tickets */
    private function reminders(array $tickets): void
    {
        $titles = ['Retornar ao contato sobre o atendimento', 'Confirmar análise do setor responsável', 'Acompanhar retorno do consultor', 'Revisar a fila de acompanhamentos'];
        for ($index = 0; $index < 48; $index++) {
            $completed = $index % 4 === 3;
            $scheduled = match ($index % 4) {
                0 => $this->clock->startOfDay()->subDay()->addHours(9),
                1 => $this->clock->startOfDay()->addHours(9 + $index % 6)->addMinutes(15),
                2 => $this->clock->startOfDay()->addDays(1 + $index % 5)->addHours(10),
                3 => $this->clock->subHours(3),
            };
            $this->record(Reminder::class, 'title', sprintf('LEM-%03d', $index + 1), $titles[$index % count($titles)], [
                'support_ticket_id' => $index < 36 ? $tickets[$index]->id : null,
                'description' => $index < 36 ? 'Conferir a evolução do atendimento e registrar o retorno recebido antes de definir o próximo passo.' : 'Revisão independente dos retornos e da rotina de suporte; não depende de um atendimento específico.',
                'scheduled_at' => $scheduled, 'completed_at' => $completed ? $this->clock->subHours(2) : null,
                'created_at' => $this->clock->subDays(12),
                'updated_at' => $completed ? $this->clock->subHours(2) : $this->clock->subHour(),
            ]);
        }
    }

    /** @param list<System> $systems */
    private function articles(array $systems): void
    {
        $number = 0;
        foreach (SupportFlowDemoCatalog::systems() as $index => $definition) {
            foreach ($definition['issues'] as $issue) {
                $this->record(KnowledgeArticle::class, 'problem', sprintf('ART-%03d', ++$number), $issue['title'], [
                    'system_id' => $systems[$index]->id,
                    'resolution_procedure' => $issue['description']."\n\nProcedimento:\n".$issue['resolution'],
                    'created_at' => $this->clock->subDays(100), 'updated_at' => $this->clock->subDay(),
                ]);
            }
        }
        foreach (SupportFlowDemoCatalog::generalArticles() as $article) {
            $this->record(KnowledgeArticle::class, 'problem', sprintf('ART-%03d', ++$number), $article['title'], [
                'system_id' => null, 'resolution_procedure' => $article['resolution'],
                'created_at' => $this->clock->subDays(100), 'updated_at' => $this->clock->subDay(),
            ]);
        }
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    private function record(string $modelClass, string $column, string $code, string $label, array $attributes): Model
    {
        $key = self::PREFIX.' '.$code.' | ';
        $matches = $modelClass::query()->whereLike($column, $key.'%')->limit(2)->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('Chave de demonstração duplicada: '.$key.' A geração foi cancelada sem mesclar ou excluir registros.');
        }

        $record = $matches->first() ?? new $modelClass;
        // Only this instance suppresses automatic timestamps. Historical dates
        // are explicitly restored, including when an unchanged row is replayed.
        $record->timestamps = false;
        $record->forceFill([
            'created_at' => $this->clock->subDays(450), 'updated_at' => $this->clock->subDays(7),
            ...$attributes, $column => $key.$label,
        ])->save();

        return $record;
    }
}
