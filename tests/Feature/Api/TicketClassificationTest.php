<?php

use App\Models\Contact;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\System;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->system = System::create(['name' => 'Indicar']);
    $this->otherSystem = System::create(['name' => 'iLube']);
    $this->contact = Contact::create(['name' => 'Ana']);
    $this->category = ProblemCategory::create(['name' => 'Falha de acesso']);
    $this->category->systems()->attach([$this->system->id, $this->otherSystem->id], ['is_active' => true]);
    $this->subcategory = ProblemSubcategory::create(['name' => 'Credenciais inválidas', 'problem_category_id' => $this->category->id]);
    $this->subcategory->systems()->attach([$this->system->id, $this->otherSystem->id], ['is_active' => true, 'problem_category_id' => $this->category->id]);
    $this->otherCategory = ProblemCategory::create(['name' => 'Busca por placa']);
    $this->otherCategory->systems()->attach($this->system->id, ['is_active' => true]);
    $this->payload = ['title' => 'Erro ao entrar', 'description' => 'Descrição original', 'system_id' => $this->system->id, 'contact_id' => $this->contact->id];
    $this->classification = ['problem_category_id' => $this->category->id, 'problem_subcategory_id' => $this->subcategory->id];
});

function classifiedTicket(array $payload, array $classification): array
{
    return test()->postJson('/api/support-tickets', [...$payload, ...$classification])->assertCreated()->json('data');
}

test('tickets can be created with a full, partial or absent classification', function (string $kind) {
    $classification = match ($kind) {
        'full' => $this->classification,
        'category' => ['problem_category_id' => $this->category->id],
        default => [],
    };
    $data = classifiedTicket($this->payload, $classification);
    expect($data['description'])->toBe('Descrição original');
    expect($data['problem_category_id'])->toBe($classification['problem_category_id'] ?? null);
    expect($data['problem_subcategory_id'])->toBe($classification['problem_subcategory_id'] ?? null);
    expect($data['problem_subcategory_ids'])->toBe($kind === 'full' ? [$this->subcategory->id] : []);
    expect($data['problem_category']['name'] ?? null)->toBe($kind === 'none' ? null : 'Falha de acesso');
    expect($data['problem_subcategory']['name'] ?? null)->toBe($kind === 'full' ? 'Credenciais inválidas' : null);
    if ($kind === 'full') {
        expect($this->category->supportTickets()->sole()->id)->toBe($data['id']);
        expect($this->subcategory->supportTickets()->sole()->id)->toBe($data['id']);
    }
})->with(['full', 'category', 'none']);

test('legacy tickets can be classified later and classification can be changed or explicitly removed', function () {
    $data = classifiedTicket($this->payload, []);
    $path = '/api/support-tickets/'.$data['id'];
    $this->patchJson($path, $this->classification)->assertOk()->assertJsonPath('data.problem_subcategory.id', $this->subcategory->id);
    $this->patchJson($path, ['problem_category_id' => $this->otherCategory->id])
        ->assertOk()->assertJsonPath('data.problem_category_id', $this->otherCategory->id)->assertJsonPath('data.problem_subcategory_ids', []);
    $this->patchJson($path, ['problem_category_id' => null, 'problem_subcategory_id' => null])
        ->assertOk()->assertJsonPath('data.problem_category', null)->assertJsonPath('data.description', 'Descrição original');
});

test('a subcategory cannot be saved without its category or under another category', function () {
    $this->postJson('/api/support-tickets', [...$this->payload, 'problem_subcategory_id' => $this->subcategory->id])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_subcategory_id');
    $this->postJson('/api/support-tickets', [...$this->payload, ...$this->classification, 'problem_category_id' => $this->otherCategory->id])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_subcategory_id');
    $data = classifiedTicket($this->payload, $this->classification);
    $this->patchJson('/api/support-tickets/'.$data['id'], ['problem_category_id' => null, 'problem_subcategory_id' => $this->subcategory->id])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_subcategory_id');
    $this->getJson('/api/support-tickets/'.$data['id'])->assertJsonPath('data.problem_category_id', $this->category->id)->assertJsonPath('data.problem_subcategory_id', $this->subcategory->id);
});

test('creates, filters, replaces and clears multiple subcategories with a safe legacy projection', function () {
    $second = ProblemSubcategory::create(['name' => 'Sessão expirada', 'problem_category_id' => $this->category->id]);
    $third = ProblemSubcategory::create(['name' => 'Usuário bloqueado', 'problem_category_id' => $this->category->id]);
    foreach ([$second, $third] as $record) {
        $record->systems()->attach($this->system->id, ['is_active' => true, 'problem_category_id' => $this->category->id]);
    }
    $first = $this->subcategory->id;
    $data = classifiedTicket($this->payload, ['problem_category_id' => $this->category->id, 'problem_subcategory_ids' => [$third->id, $first, $second->id]]);
    expect($data['problem_subcategory_ids'])->toBe([$first, $second->id, $third->id]);
    expect(array_column($data['problem_subcategories'], 'id'))->toBe($data['problem_subcategory_ids']);
    expect($data['problem_subcategory_id'])->toBe($first);
    $this->assertDatabaseCount('problem_subcategory_support_ticket', 3);
    $this->getJson('/api/support-tickets?problem_subcategory_id='.$third->id)->assertOk()
        ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $data['id']);
    $this->getJson('/api/support-tickets?problem_subcategory_id=999999')->assertOk()->assertJsonCount(0, 'data');
    $path = '/api/support-tickets/'.$data['id'];
    $this->patchJson($path, ['problem_subcategory_id' => null])->assertUnprocessable()->assertJsonValidationErrors('problem_subcategory_id');
    $this->patchJson($path, ['problem_subcategory_id' => $first])
        ->assertOk()->assertJsonPath('data.problem_subcategory_ids', $data['problem_subcategory_ids']);

    $this->patchJson($path, ['problem_subcategory_ids' => [$second->id]])->assertOk()
        ->assertJsonPath('data.problem_subcategory_id', $second->id);
    $this->patchJson($path, ['problem_subcategory_ids' => [$second->id, $third->id]])->assertOk()
        ->assertJsonPath('data.problem_subcategory_ids', [$second->id, $third->id]);
    $this->patchJson($path, ['problem_subcategory_ids' => []])->assertOk()
        ->assertJsonPath('data.problem_subcategories', [])->assertJsonPath('data.problem_subcategory_id', null);
    $this->assertDatabaseCount('problem_subcategory_support_ticket', 0);
});

test('rejects individual invalid or duplicate list entries and mixed API contracts atomically', function () {
    $wrong = ProblemSubcategory::create(['name' => 'Não encontrada', 'problem_category_id' => $this->otherCategory->id]);
    $base = [...$this->payload, 'problem_category_id' => $this->category->id];
    $id = $this->subcategory->id;
    foreach ([
        [[$id, $id], ['problem_subcategory_ids.0', 'problem_subcategory_ids.1']],
        [[(string) $id, true], ['problem_subcategory_ids.0', 'problem_subcategory_ids.1']],
        [[$id, 999999, $wrong->id], ['problem_subcategory_ids.1', 'problem_subcategory_ids.2']],
        [[null, 'invalid'], ['problem_subcategory_ids.0', 'problem_subcategory_ids.1']],
        [['nested' => $id], ['problem_subcategory_ids']],
        [null, ['problem_subcategory_ids']],
    ] as [$selection, $errors]) {
        $this->postJson('/api/support-tickets', [...$base, 'problem_subcategory_ids' => $selection])
            ->assertUnprocessable()->assertJsonValidationErrors($errors);
    }
    $this->postJson('/api/support-tickets', [...$base, 'problem_subcategory_ids' => [], 'problem_subcategory_id' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_subcategory_ids');
    $this->assertDatabaseCount('support_tickets', 0);
});

test('retains historical links independently when adding or removing eligible subcategories', function () {
    $second = ProblemSubcategory::create(['name' => 'Sessão expirada', 'problem_category_id' => $this->category->id]);
    $second->systems()->attach($this->system->id, ['is_active' => true, 'problem_category_id' => $this->category->id]);
    $data = classifiedTicket($this->payload, $this->classification);
    $this->subcategory->update(['is_active' => false]);
    $path = '/api/support-tickets/'.$data['id'];
    $ids = [$this->subcategory->id, $second->id];
    $this->patchJson($path, ['problem_subcategory_ids' => $ids])->assertOk()
        ->assertJsonPath('data.problem_subcategory_ids', $ids)->assertJsonPath('data.problem_subcategories.0.is_active', false);
    $this->patchJson($path, ['system_id' => $this->otherSystem->id, 'problem_subcategory_ids' => $ids])
        ->assertUnprocessable()->assertJsonValidationErrors(['problem_subcategory_ids.0', 'problem_subcategory_ids.1']);
    $this->getJson($path)->assertJsonPath('data.problem_subcategory_ids', $ids);
    $this->category->update(['is_active' => false]);
    $this->patchJson($path, ['problem_subcategory_ids' => [$this->subcategory->id]])->assertOk()
        ->assertJsonPath('data.problem_subcategory_ids', [$this->subcategory->id]);
    $this->patchJson($path, ['problem_category_id' => $this->otherCategory->id])->assertOk()
        ->assertJsonPath('data.problem_subcategory_ids', []);
});

test('invalid classification identifiers use field validation errors', function (string $field, mixed $value) {
    $this->postJson('/api/support-tickets', [...$this->payload, ...$this->classification, $field => $value])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('support_tickets', 0);
})->with([
    ['problem_category_id', 999999], ['problem_subcategory_id', 999999],
    ['problem_category_id', [1]], ['problem_subcategory_id', [1]],
    ['problem_category_id', 'invalid'], ['problem_subcategory_id', false],
]);

test('new classifications require active records and both enabled system associations', function (string $condition, string $field) {
    if ($condition === 'missing category system') {
        $this->subcategory->systems()->detach($this->system->id);
    }
    match ($condition) {
        'inactive category' => $this->category->update(['is_active' => false]),
        'inactive subcategory' => $this->subcategory->update(['is_active' => false]),
        'missing category system' => $this->category->systems()->detach($this->system->id),
        'disabled category system' => $this->category->systems()->updateExistingPivot($this->system->id, ['is_active' => false]),
        'missing subcategory system' => $this->subcategory->systems()->detach($this->system->id),
        'disabled subcategory system' => $this->subcategory->systems()->updateExistingPivot($this->system->id, ['is_active' => false]),
    };
    $this->postJson('/api/support-tickets', [...$this->payload, ...$this->classification])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('support_tickets', 0);
})->with([
    ['inactive category', 'problem_category_id'], ['inactive subcategory', 'problem_subcategory_id'],
    ['missing category system', 'problem_category_id'], ['disabled category system', 'problem_category_id'],
    ['missing subcategory system', 'problem_subcategory_id'], ['disabled subcategory system', 'problem_subcategory_id'],
]);

test('historical classifications survive unrelated edits and explicit resubmission of the same identifiers', function (string $condition) {
    $data = classifiedTicket($this->payload, $this->classification);
    match ($condition) {
        'category' => $this->category->update(['is_active' => false]),
        'subcategory' => $this->subcategory->update(['is_active' => false]),
        'system' => $this->system->update(['is_active' => false]),
        'category availability' => $this->category->systems()->updateExistingPivot($this->system->id, ['is_active' => false]),
        'subcategory availability' => $this->subcategory->systems()->updateExistingPivot($this->system->id, ['is_active' => false]),
    };
    $path = '/api/support-tickets/'.$data['id'];
    $this->patchJson($path, ['title' => 'Título revisado'])->assertOk()->assertJsonPath('data.problem_category_id', $this->category->id)->assertJsonPath('data.problem_subcategory_id', $this->subcategory->id);
    $this->putJson($path, [...$this->payload, ...$this->classification])->assertOk()->assertJsonPath('data.problem_subcategory.name', $this->subcategory->name);
    $this->patchJson($path.'/status', ['status' => 'in_progress'])->assertOk();
    $this->postJson($path.'/close', ['resolution' => 'Correção aplicada'])->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.problem_category_id', $this->category->id);
})->with(['category', 'subcategory', 'system', 'category availability', 'subcategory availability']);

test('new subcategory assignments cannot reuse an inactive historical parent or system', function (string $condition) {
    $data = classifiedTicket($this->payload, ['problem_category_id' => $this->category->id]);
    match ($condition) {
        'category' => $this->category->update(['is_active' => false]),
        'system' => $this->system->update(['is_active' => false]),
        'availability' => $this->category->systems()->updateExistingPivot($this->system->id, ['is_active' => false]),
    };
    $this->patchJson('/api/support-tickets/'.$data['id'], ['problem_subcategory_id' => $this->subcategory->id])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_category_id');
    $this->patchJson('/api/support-tickets/'.$data['id'], ['problem_category_id' => null, 'problem_subcategory_id' => null])->assertOk();
})->with(['category', 'system', 'availability']);

test('historical subcategories can be explicitly removed while keeping an inactive historical category', function () {
    $data = classifiedTicket($this->payload, $this->classification);
    $this->category->update(['is_active' => false]);
    $this->patchJson('/api/support-tickets/'.$data['id'], ['problem_subcategory_id' => null])
        ->assertOk()->assertJsonPath('data.problem_category_id', $this->category->id)->assertJsonPath('data.problem_subcategory_id', null);
});

test('changing system revalidates the complete classification and never silently clears it', function () {
    $data = classifiedTicket($this->payload, $this->classification);
    $path = '/api/support-tickets/'.$data['id'];
    $this->patchJson($path, ['system_id' => $this->otherSystem->id])->assertOk()->assertJsonPath('data.problem_category_id', $this->category->id)->assertJsonPath('data.problem_subcategory_id', $this->subcategory->id);
    $this->subcategory->systems()->updateExistingPivot($this->system->id, ['is_active' => false]);
    $this->patchJson($path, ['system_id' => $this->system->id, 'title' => 'Não salvar'])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_subcategory_id');
    $this->getJson($path)->assertJsonPath('data.system_id', $this->otherSystem->id)->assertJsonPath('data.title', $this->payload['title']);
    $this->patchJson($path, ['system_id' => $this->system->id, 'problem_subcategory_id' => null])->assertOk();
    $this->category->systems()->updateExistingPivot($this->otherSystem->id, ['is_active' => false]);
    $this->patchJson($path, ['system_id' => $this->otherSystem->id])->assertUnprocessable()->assertJsonValidationErrors('problem_category_id');
    $this->patchJson($path, ['system_id' => $this->otherSystem->id, 'problem_category_id' => null, 'problem_subcategory_id' => null])->assertOk();
});

test('classification does not relax the existing inactive-system rule', function () {
    $this->otherSystem->update(['is_active' => false]);
    $data = classifiedTicket($this->payload, $this->classification);
    $this->patchJson('/api/support-tickets/'.$data['id'], ['system_id' => $this->otherSystem->id, 'problem_category_id' => null, 'problem_subcategory_id' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('system_id');
    $this->postJson('/api/support-tickets', [...$this->payload, 'system_id' => $this->otherSystem->id])->assertUnprocessable()->assertJsonValidationErrors('system_id');
});

test('selection filters require context and hide inactive and unavailable classifications', function () {
    $categoryUrl = '/api/problem-categories?selectable=1&system_id='.$this->system->id;
    $subcategoryUrl = '/api/problem-subcategories?selectable=1&system_id='.$this->system->id.'&problem_category_id='.$this->category->id;
    $this->getJson('/api/problem-categories?selectable=1')->assertUnprocessable()->assertJsonValidationErrors('system_id');
    $this->getJson('/api/problem-subcategories?selectable=1&system_id='.$this->system->id)->assertUnprocessable()->assertJsonValidationErrors('problem_category_id');
    $this->getJson($categoryUrl)->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($subcategoryUrl)->assertOk()->assertJsonPath('data.0.id', $this->subcategory->id);
    $this->subcategory->update(['is_active' => false]);
    $this->getJson($subcategoryUrl)->assertJsonCount(0, 'data');
    $this->subcategory->update(['is_active' => true]);
    $this->category->systems()->updateExistingPivot($this->system->id, ['is_active' => false]);
    $this->getJson($categoryUrl)->assertJsonCount(1, 'data');
    $this->getJson($subcategoryUrl)->assertJsonCount(0, 'data');
    $this->category->systems()->updateExistingPivot($this->system->id, ['is_active' => true]);
    $this->category->update(['is_active' => false]);
    $this->getJson($subcategoryUrl)->assertJsonCount(0, 'data');
    $this->system->update(['is_active' => false]);
    $this->getJson($categoryUrl)->assertJsonCount(0, 'data');
    // Management lookups are unaffected by the opt-in selection filter.
    $this->getJson('/api/problem-categories')->assertJsonCount(2, 'data');
});

test('consultations return historical names without lazy loading and classification foreign keys prevent lost history', function () {
    $data = classifiedTicket($this->payload, $this->classification);
    $follow = classifiedTicket([...$this->payload, 'previous_ticket_id' => $data['id']], $this->classification);
    $this->category->update(['is_active' => false]);
    $this->subcategory->update(['is_active' => false]);
    Model::preventLazyLoading();
    try {
        $this->getJson('/api/support-tickets/'.$data['id'])->assertOk()->assertJsonPath('data.problem_category.name', $this->category->name)->assertJsonPath('data.problem_subcategory.is_active', false);
        $this->getJson('/api/support-tickets')->assertOk()->assertJsonPath('data.0.problem_subcategory.name', $this->subcategory->name);
        $this->getJson('/api/support-tickets/'.$data['id'].'/related')->assertOk()->assertJsonPath('data.0.id', $follow['id']);
    } finally {
        Model::preventLazyLoading(false);
    }
    expect(fn () => DB::table('problem_categories')->where('id', $this->category->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('problem_subcategories')->where('id', $this->subcategory->id)->delete())->toThrow(QueryException::class);
    expect(fn () => DB::table('support_tickets')->where('id', $data['id'])->update(['problem_category_id' => $this->otherCategory->id]))->toThrow(QueryException::class);
});
