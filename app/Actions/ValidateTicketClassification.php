<?php

namespace App\Actions;

use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidateTicketClassification
{
    /**
     * Resolve the desired links while the ticket row is locked.
     *
     * @param  array<string, mixed>  $data
     * @return list<int>|null Null means the persisted links need no update.
     */
    public function execute(SupportTicket $ticket, array $data): ?array
    {
        $plural = array_key_exists('problem_subcategory_ids', $data);
        $legacy = array_key_exists('problem_subcategory_id', $data);
        if ($plural && $legacy) {
            throw ValidationException::withMessages(['problem_subcategory_ids' => 'Envie apenas um dos campos de subcategoria.']);
        }
        if (! $plural && ! $legacy && ! array_key_exists('problem_category_id', $data) && ! isset($data['system_id'])) {
            return null;
        }

        $current = $ticket->exists
            ? DB::table(SupportTicket::SUBCATEGORY_PIVOT)->where('support_ticket_id', $ticket->id)
                ->orderBy('problem_subcategory_id')->lockForUpdate()->pluck('problem_subcategory_id')
                ->map(fn ($id): int => (int) $id)->values()->all()
            : [];
        $systemId = (int) ($data['system_id'] ?? $ticket->system_id);
        $categoryId = array_key_exists('problem_category_id', $data) ? $data['problem_category_id'] : $ticket->problem_category_id;
        $categoryId = $categoryId === null ? null : (int) $categoryId;
        $sameCategory = $categoryId === $ticket->problem_category_id;
        $retainingCategory = $ticket->exists && $sameCategory && $systemId === $ticket->system_id;

        if ($plural) {
            $ids = array_map(intval(...), $data['problem_subcategory_ids']);
        } elseif ($legacy) {
            $legacyId = isset($data['problem_subcategory_id']) ? (int) $data['problem_subcategory_id'] : null;
            if (count($current) > 1 && $legacyId !== $current[0]) {
                throw ValidationException::withMessages(['problem_subcategory_id' => 'Use a lista de subcategorias para alterar um atendimento com múltiplos vínculos.']);
            }
            $ids = count($current) > 1 ? $current : ($legacyId === null ? [] : [$legacyId]);
        } else {
            // Each subcategory has exactly one owner, so none can travel to a different category.
            $ids = $sameCategory ? $current : [];
        }

        $field = $legacy || (! $plural && count($current) === 1) ? 'problem_subcategory_id' : 'problem_subcategory_ids';
        $toValidate = $retainingCategory ? array_diff($ids, $current) : $ids;
        if ($categoryId === null && $ids !== []) {
            throw ValidationException::withMessages([$field => 'Selecione uma categoria para vincular subcategorias.']);
        }
        if ($categoryId !== null && (! $retainingCategory || $toValidate !== [])) {
            $this->validateAssignment($categoryId, $systemId, $toValidate, $plural, $field);
        }

        sort($ids);

        return $ids === $current ? null : $ids;
    }

    /** @param array<int, int> $ids */
    private function validateAssignment(int $categoryId, int $systemId, array $ids, bool $indexed, string $field): void
    {
        // Match the category -> subcategory -> system lock order used by their management.
        $category = ProblemCategory::query()->whereKey($categoryId)->lockForUpdate()->first();
        if ($category === null) {
            throw ValidationException::withMessages(['problem_category_id' => 'A categoria informada não existe.']);
        }
        $subcategories = $ids === [] ? collect() : ProblemSubcategory::query()->whereIntegerInRaw('id', $ids)
            ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $system = System::query()->whereKey($systemId)->lockForUpdate()->first();
        $categoryAvailable = DB::table('problem_category_system')->where('problem_category_id', $categoryId)
            ->where('system_id', $systemId)->where('is_active', true)->exists();
        if (! $category->is_active || ! $categoryAvailable || ! $system?->is_active) {
            throw ValidationException::withMessages(['problem_category_id' => 'Novas classificações exigem uma categoria ativa e disponível para o sistema ativo do atendimento. Selecione outra categoria ou remova a classificação.']);
        }
        if ($ids === []) {
            return;
        }

        $available = DB::table('problem_subcategory_system')->where('problem_category_id', $categoryId)
            ->where('system_id', $systemId)->where('is_active', true)
            ->whereIntegerInRaw('problem_subcategory_id', $ids)->pluck('problem_subcategory_id')->flip();
        $errors = [];
        foreach ($ids as $index => $id) {
            $subcategory = $subcategories->get($id);
            $errorKey = $indexed ? $field.'.'.$index : $field;
            if ($subcategory === null) {
                $errors[$errorKey][] = 'A subcategoria informada não existe.';
            } elseif ($subcategory->problem_category_id !== $categoryId) {
                $errors[$errorKey][] = 'Selecione uma subcategoria pertencente à categoria do atendimento.';
            } elseif (! $subcategory->is_active || ! $available->has($id)) {
                $errors[$errorKey][] = "A subcategoria {$subcategory->name} está inativa ou indisponível para este sistema.";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
