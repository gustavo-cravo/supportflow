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
    /** @param array<string, mixed> $data */
    public function execute(SupportTicket $ticket, array $data): void
    {
        $systemId = (int) ($data['system_id'] ?? $ticket->system_id);
        $categoryId = array_key_exists('problem_category_id', $data) ? $data['problem_category_id'] : $ticket->problem_category_id;
        $subcategoryId = array_key_exists('problem_subcategory_id', $data) ? $data['problem_subcategory_id'] : $ticket->problem_subcategory_id;
        $categoryId = $categoryId === null ? null : (int) $categoryId;
        $subcategoryId = $subcategoryId === null ? null : (int) $subcategoryId;

        if ($categoryId === null) {
            if ($subcategoryId !== null) {
                throw ValidationException::withMessages(['problem_subcategory_id' => 'Uma subcategoria exige uma categoria. Selecione uma categoria ou remova a subcategoria explicitamente.']);
            }

            return;
        }

        $retainingCategory = $ticket->exists && $systemId === $ticket->system_id && $categoryId === $ticket->problem_category_id;
        $retainingSubcategory = $retainingCategory && $subcategoryId === $ticket->problem_subcategory_id;
        // Unrelated edits, status changes and closure preserve the historical tuple.
        if ($retainingCategory && ($retainingSubcategory || $subcategoryId === null)) {
            return;
        }

        // Match the category -> subcategory -> system lock order used by their management.
        $category = ProblemCategory::query()->whereKey($categoryId)->lockForUpdate()->first();
        if ($category === null) {
            throw ValidationException::withMessages(['problem_category_id' => 'A categoria informada não existe.']);
        }
        $subcategory = $subcategoryId === null ? null : ProblemSubcategory::query()->whereKey($subcategoryId)->lockForUpdate()->first();
        $system = System::query()->whereKey($systemId)->lockForUpdate()->first();
        $categoryAvailable = DB::table('problem_category_system')->where('problem_category_id', $categoryId)
            ->where('system_id', $systemId)->where('is_active', true)->exists();
        if (! $category->is_active || ! $categoryAvailable || ! $system?->is_active) {
            throw ValidationException::withMessages(['problem_category_id' => 'Novas classificações exigem uma categoria ativa e disponível para o sistema ativo do atendimento. Selecione outra categoria ou remova a classificação.']);
        }
        if ($subcategoryId !== null) {
            if ($subcategory === null || $subcategory->problem_category_id !== $categoryId) {
                throw ValidationException::withMessages(['problem_subcategory_id' => 'Selecione uma subcategoria pertencente à categoria do atendimento ou deixe o campo vazio.']);
            }
            $subcategoryAvailable = DB::table('problem_subcategory_system')->where('problem_subcategory_id', $subcategoryId)
                ->where('problem_category_id', $categoryId)->where('system_id', $systemId)->where('is_active', true)->exists();
            if (! $subcategory->is_active || ! $subcategoryAvailable) {
                throw ValidationException::withMessages(['problem_subcategory_id' => 'A subcategoria está inativa ou indisponível para este sistema. Selecione outra subcategoria ou remova-a explicitamente.']);
            }
        }
    }
}
