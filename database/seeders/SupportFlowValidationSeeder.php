<?php

namespace Database\Seeders;

use App\Enums\PendingTaskStatus;
use App\Enums\TicketStatus;
use App\Enums\VerificationSource;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Models\PendingTask;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\Reminder;
use App\Models\SupportTicket;
use App\Models\System;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SupportFlowValidationSeeder extends Seeder
{
    public const string PREFIX = 'SFT-QA';

    public const string MARKER = 'SFT-QA:validation:v1';

    private CarbonImmutable $clock;

    /** @return array<class-string<Model>, array{column: string, marker: string, code: string, count: int}> */
    public static function entities(): array
    {
        return [
            System::class => ['column' => 'name', 'marker' => 'description', 'code' => 'SIS', 'count' => 3],
            ProblemCategory::class => ['column' => 'name', 'marker' => 'description', 'code' => 'CAT', 'count' => 3],
            ProblemSubcategory::class => ['column' => 'name', 'marker' => 'description', 'code' => 'SUB', 'count' => 7],
            Contact::class => ['column' => 'name', 'marker' => 'notes', 'code' => 'CON', 'count' => 5],
            Customer::class => ['column' => 'name', 'marker' => 'notes', 'code' => 'CLI', 'count' => 3],
            SupportTicket::class => ['column' => 'title', 'marker' => 'description', 'code' => 'ATE', 'count' => 24],
            PendingTask::class => ['column' => 'description', 'marker' => 'description', 'code' => 'PEN', 'count' => 8],
            Reminder::class => ['column' => 'title', 'marker' => 'description', 'code' => 'LEM', 'count' => 8],
            KnowledgeArticle::class => ['column' => 'problem', 'marker' => 'resolution_procedure', 'code' => 'ART', 'count' => 5],
        ];
    }

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('SupportFlowValidationSeeder só pode ser executado nos ambientes local ou testing.');
        }
        $this->clock = CarbonImmutable::now('America/Sao_Paulo')->startOfSecond();
        $created = DB::transaction(function (): bool {
            $found = false;
            foreach (self::entities() as $class => $definition) {
                $found = $class::query()->whereLike($definition['column'], self::PREFIX.' %')
                    ->orWhereLike($definition['marker'], '%'.self::MARKER.'%')->exists() || $found;
            }
            if ($found) {
                $this->assertComplete();

                return false;
            }
            $this->populate();

            return true;
        });
        $this->command->info($created ? 'Base SFT-QA criada. Nenhum registro anterior foi alterado.' : 'Base SFT-QA já existente. Registros, edições e datas foram preservados.');
        (new SupportFlowValidationReference)->print($this->command);
    }

    private function assertComplete(): void
    {
        foreach (self::entities() as $class => $definition) {
            if ($class::whereLike($definition['column'], self::PREFIX.' %')->count() !== $definition['count']) {
                throw new RuntimeException('Conjunto SFT-QA incompleto ou conflitante. Nenhum registro será alterado; utilize uma base de testes separada.');
            }
            for ($number = 1; $number <= $definition['count']; $number++) {
                $prefix = sprintf('%s %s-%03d | ', self::PREFIX, $definition['code'], $number);
                if ($class::whereLike($definition['column'], $prefix.'%')->whereLike($definition['marker'], '%'.self::MARKER.'%')->count() !== 1) {
                    throw new RuntimeException('Identificação SFT-QA duplicada, removida ou em conflito: '.$prefix.' Nenhum registro foi alterado.');
                }
            }
        }
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    private function insert(string $class, int $number, string $label, array $attributes = []): Model
    {
        $definition = self::entities()[$class];
        $marker = $definition['marker'];
        $column = $definition['column'];
        $record = new $class;
        $record->timestamps = false;
        $record->forceFill([
            'created_at' => $this->clock->subDays(500), 'updated_at' => $this->clock,
            ...$attributes,
            $column => sprintf('%s %s-%03d | %s', self::PREFIX, $definition['code'], $number, $label),
        ]);
        $record->setAttribute($marker, $record->getAttribute($marker)."\n".self::MARKER.' - Dados inteiramente fictícios para validação.');
        $record->save();

        return $record;
    }

    private function populate(): void
    {
        $curated = SupportFlowDemoCatalog::systems();
        $systems = [];
        foreach (['Indicar fictício', 'iLube fictício', 'Legado fictício'] as $index => $name) {
            $systems[] = $this->insert(System::class, $index + 1, $name, ['description' => $curated[[4, 1, 6][$index]]['description']]);
        }
        $contacts = [];
        foreach (['Ana Exemplo', 'Bruno Exemplo', 'Carla Exemplo', 'Diego Exemplo', 'Eva Exemplo'] as $index => $name) {
            $contacts[] = $this->insert(Contact::class, $index + 1, $name, [
                'phone' => $index === 4 ? null : sprintf('(11) 00000-%04d', $index + 1),
                'email' => $index === 3 ? null : 'contato.'.($index + 1).'@sft-qa.example.test',
                'notes' => 'Contato fictício para conferir consulta rápida e cópia de campos opcionais.',
            ]);
        }
        $customers = [];
        foreach (['Oficina Exemplo', 'Loja Exemplo', 'Serviços Exemplo'] as $index => $name) {
            $customers[] = $this->insert(Customer::class, $index + 1, $name, [
                'document' => ['00000000000100', '00000000000', null][$index],
                'phone' => $index === 2 ? null : '(11) 0000-000'.($index + 1),
                'email' => 'cliente.'.($index + 1).'@sft-qa.example.test',
                'notes' => 'Empresa fictícia. Documento sintético, sem validade cadastral.',
            ]);
        }
        $categories = $subcategories = [];
        $number = 0;
        foreach (SupportFlowDemoCatalog::validationClassifications() as $key => $definition) {
            $category = $categories[$key] = $this->insert(ProblemCategory::class, count($categories) + 1, $definition['name']);
            foreach ($definition['systems'] as $index) {
                $category->systems()->attach($systems[$index]->id, ['is_active' => true, 'created_at' => $this->clock->subDays(500), 'updated_at' => $this->clock->subDays(500)]);
            }
            foreach ($definition['subcategories'] as $subkey => $sub) {
                $subcategory = $subcategories[$subkey] = $this->insert(ProblemSubcategory::class, ++$number, $sub['name'], ['problem_category_id' => $category->id]);
                foreach ($sub['systems'] as $index) {
                    $subcategory->systems()->attach($systems[$index]->id, ['problem_category_id' => $category->id, 'is_active' => true, 'created_at' => $this->clock->subDays(500), 'updated_at' => $this->clock->subDays(500)]);
                }
            }
        }
        $tickets = [];
        foreach (array_reverse(SupportFlowDemoCatalog::validationTickets(), true) as $index => $definition) {
            [$days, $system, $category, $subcategory, $status] = $definition;
            $code = $index + 1;
            $parent = isset($definition[5]) ? $tickets[$definition[5]] : null;
            $opened = $this->clock->startOfDay()->subDays($days)->addHours(9 + $index % 7)->min($this->clock);
            $finished = in_array($status, [TicketStatus::Closed, TicketStatus::Resolved], true);
            $activity = $opened->addHours(2)->min($this->clock);
            $contact = $parent ? Contact::findOrFail($parent->contact_id) : $contacts[$index % count($contacts)];
            $customerId = $parent ? $parent->customer_id : ($index % 4 === 3 ? null : $customers[$index % count($customers)]->id);
            $label = $category ? SupportFlowDemoCatalog::validationClassifications()[$category]['name'] : 'Histórico sem classificação';
            $tickets[$code] = $this->insert(SupportTicket::class, $code, ($parent ? 'Retorno: ' : '').$label, [
                'system_id' => $systems[$system]->id, 'contact_id' => $contact->id, 'customer_id' => $customerId,
                'previous_ticket_id' => $parent?->id, 'problem_category_id' => $category ? $categories[$category]->id : null,
                'problem_subcategory_id' => $subcategory ? $subcategories[$subcategory]->id : null,
                'description' => 'Cenário fictício '.$code.': '.$label.'. Conferir reprodução, pesquisa, filtros e histórico.'.($parent ? ' Retorno independente do atendimento anterior.' : ''),
                'status' => $status->value, 'created_at' => $opened, 'updated_at' => $activity,
                'resolution' => $finished ? 'Procedimento fictício: revisar configuração, repetir a operação e registrar a conferência do resultado.' : null,
                'verification_source' => $finished ? ($index % 2 === 0 ? VerificationSource::Customer->value : VerificationSource::ResponsibleDepartment->value) : null,
                'verified_at' => $finished ? $activity : null, 'verified_by' => $finished ? 'Responsável fictício SFT-QA' : null,
                'verification_notes' => $finished ? 'Resultado confirmado em ambiente de validação, sem dados reais.' : null,
                'closed_at' => $status === TicketStatus::Closed ? $activity : null,
            ]);
            if ($subcategory) {
                $tickets[$code]->problemSubcategories()->attach($subcategories[$subcategory]->id, [
                    'problem_category_id' => $tickets[$code]->problem_category_id,
                ]);
            }
        }
        $this->activities($tickets);
        $articles = [$curated[4]['issues'][0], $curated[1]['issues'][0], $curated[6]['issues'][0], ...array_slice(SupportFlowDemoCatalog::generalArticles(), 0, 2)];
        foreach ($articles as $index => $article) {
            $this->insert(KnowledgeArticle::class, $index + 1, $article['title'], [
                'system_id' => $index < 3 ? $systems[$index]->id : null,
                'resolution_procedure' => ($article['description'] ?? 'Orientação geral fictícia para a rotina de suporte.')."\n\nProcedimento:\n".$article['resolution'],
            ]);
        }
        // Only instances created in this transaction are changed. Their historical
        // classifications and tickets predate deactivations and lost availability.
        $systems[2]->forceFill(['is_active' => false, 'updated_at' => $this->clock->subDays(60)])->save();
        $categories['integration']->forceFill(['is_active' => false, 'updated_at' => $this->clock->subDays(16)])->save();
        $subcategories['blocked']->forceFill(['is_active' => false, 'updated_at' => $this->clock->subDays(8)])->save();
        $subcategories['mapping']->forceFill(['is_active' => false, 'updated_at' => $this->clock->subDays(16)])->save();
        $categories['integration']->systems()->updateExistingPivot($systems[1]->id, ['is_active' => false, 'updated_at' => $this->clock->subDays(16)]);
        foreach (['queue', 'mapping'] as $key) {
            $subcategories[$key]->systems()->updateExistingPivot($systems[1]->id, ['is_active' => false, 'updated_at' => $this->clock->subDays(16)]);
        }
    }

    /** @param array<int, SupportTicket> $tickets */
    private function activities(array $tickets): void
    {
        for ($index = 0; $index < 8; $index++) {
            $completed = $index >= 5;
            $ticket = $index < 5 ? $tickets[[9, 4, 7, 5, 10][$index]] : null;
            $created = $this->clock->subDays(3);
            $this->insert(PendingTask::class, $index + 1, ['Conferir evidências após encerramento', 'Aguardar retorno do setor', 'Revisar prevenção de reincidência', 'Validar procedimento com contato', 'Pendência sem prazo', 'Documentar análise', 'Conferir integração', 'Finalizar revisão'][$index], [
                'support_ticket_id' => $ticket?->id, 'status' => $completed ? PendingTaskStatus::Completed->value : PendingTaskStatus::Pending->value,
                'due_at' => $index < 2 ? $this->clock->subHours(6) : ($index < 4 ? $this->clock->addDays(2) : null),
                'created_at' => $ticket ? $created->max($ticket->created_at) : $created,
                'completed_at' => $completed ? $this->clock->subDay() : null,
                'completion_notes' => $completed ? 'Revisão fictícia concluída; resultado documentado.' : null,
            ]);
        }
        for ($index = 0; $index < 8; $index++) {
            $scheduled = match ($index) {
                0, 1 => $this->clock->subDay(),
                2, 3 => $this->clock->startOfDay()->addHours(9 + $index),
                4, 5 => $this->clock->addDays(2),
                default => $this->clock->subDays(2),
            };
            $ticket = $index % 2 === 0 ? $tickets[9] : null;
            $this->insert(Reminder::class, $index + 1, ['Retorno vencido', 'Revisão independente vencida', 'Retorno de hoje', 'Conferência de hoje', 'Retorno futuro', 'Revisão futura independente', 'Retorno concluído', 'Conferência concluída'][$index], [
                'support_ticket_id' => $ticket?->id, 'description' => 'Conferir andamento e registrar os próximos passos da validação.',
                'scheduled_at' => $scheduled, 'created_at' => $this->clock->subDays(3),
                'completed_at' => $index >= 6 ? $this->clock->subDay() : null,
            ]);
        }
    }
}
