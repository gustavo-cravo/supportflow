<?php

use App\Models\Contact;
use App\Models\Customer;
use App\Models\ProblemCategory;
use App\Models\ProblemSubcategory;
use App\Models\SupportTicket;
use App\Models\System;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->indicar = System::create(['name' => 'Indicar']);
    $this->ilube = System::create(['name' => 'iLube']);
    $this->way = System::create(['name' => '.way']);
});

function createProblemCategory(array $systemIds, string $name = 'Falha de acesso'): array
{
    return test()->postJson('/api/problem-categories', ['name' => $name, 'system_ids' => $systemIds])
        ->assertCreated()->json('data');
}

function createProblemSubcategory(array $category, array $systemIds, string $name = 'Credenciais inválidas'): array
{
    return test()->postJson('/api/problem-subcategories', [
        'problem_category_id' => $category['id'], 'name' => $name, 'system_ids' => $systemIds,
    ])->assertCreated()->json('data');
}

test('categories are shared across systems and return current and historical associations', function () {
    $category = createProblemCategory([$this->indicar->id, $this->ilube->id, $this->way->id]);
    expect($category['is_active'])->toBeTrue();
    expect($category['system_ids'])->toEqualCanonicalizing([$this->indicar->id, $this->ilube->id, $this->way->id]);
    expect($category['system_associations'])->toHaveCount(3);
    expect($category['subcategories_count'])->toBe(0);
    $this->getJson('/api/problem-categories/'.$category['id'])->assertOk()->assertJsonPath('data.name', 'Falha de acesso');
    $this->assertDatabaseCount('problem_categories', 1);
    $this->assertDatabaseCount('problem_category_system', 3);
    expect($this->indicar->problemCategories()->sole()->id)->toBe($category['id']);
});

test('category and subcategory names reject case and whitespace duplicates in their scopes', function (string $duplicate) {
    $category = createProblemCategory([$this->indicar->id], 'Falha de acesso');
    $this->postJson('/api/problem-categories', ['name' => $duplicate, 'system_ids' => [$this->ilube->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    createProblemSubcategory($category, [$this->indicar->id], 'Falha de acesso');
    $this->postJson('/api/problem-subcategories', [
        'problem_category_id' => $category['id'], 'name' => $duplicate, 'system_ids' => [$this->indicar->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('name');
    $other = createProblemCategory([$this->way->id], 'Outra categoria');
    createProblemSubcategory($other, [$this->way->id], $duplicate);
    $this->assertDatabaseCount('problem_subcategories', 2);
})->with(['FALHA DE ACESSO', ' falha  de acesso ', "\tFalha\nde\tacesso\u{00A0}"]);

test('names are normalized consistently on create and update including accented uppercase letters', function () {
    $category = createProblemCategory([$this->indicar->id], "  SESSÃO  EXPIRADA \n");
    expect($category['name'])->toBe('SESSÃO EXPIRADA');
    $this->postJson('/api/problem-categories', ['name' => 'sessão expirada', 'system_ids' => [$this->way->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->patchJson('/api/problem-categories/'.$category['id'], ['name' => '  Sessão  expirada '])
        ->assertOk()->assertJsonPath('data.name', 'Sessão expirada');
    $other = createProblemCategory([$this->ilube->id], 'Outra');
    $this->patchJson('/api/problem-categories/'.$other['id'], ['name' => 'sessão expirada'])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(ProblemCategory::findOrFail($other['id'])->name)->toBe('Outra');
    expect(fn () => ProblemCategory::create(['name' => ' SESSÃO   EXPIRADA ']))->toThrow(QueryException::class);
});

test('subcategory names can be edited but cannot collide or change category', function () {
    $category = createProblemCategory([$this->indicar->id]);
    $first = createProblemSubcategory($category, [$this->indicar->id], 'Primeira');
    $second = createProblemSubcategory($category, [$this->indicar->id], 'Segunda');
    $other = createProblemCategory([$this->indicar->id], 'Outra');
    $this->patchJson('/api/problem-subcategories/'.$first['id'], ['name' => ' primeira ', 'description' => 'Detalhes'])
        ->assertOk()->assertJsonPath('data.description', 'Detalhes');
    $this->patchJson('/api/problem-subcategories/'.$second['id'], ['name' => ' PRIMEIRA '])
        ->assertUnprocessable()->assertJsonValidationErrors('name');
    $this->patchJson('/api/problem-subcategories/'.$first['id'], ['problem_category_id' => $other['id']])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_category_id');
    $this->patchJson('/api/problem-subcategories/'.$first['id'], ['problem_category_id' => $category['id']])->assertOk();
});

test('subcategories are available only in the selected subset of parent systems', function () {
    $category = createProblemCategory([$this->indicar->id, $this->ilube->id]);
    $subcategory = createProblemSubcategory($category, [$this->ilube->id]);
    expect($subcategory['system_ids'])->toBe([$this->ilube->id]);
    expect($subcategory['category']['id'])->toBe($category['id']);
    $this->getJson('/api/problem-subcategories?system_id='.$this->indicar->id)->assertJsonCount(0, 'data');
    $this->getJson('/api/problem-subcategories?system_id='.$this->ilube->id)->assertJsonPath('data.0.id', $subcategory['id']);
    $this->postJson('/api/problem-subcategories', [
        'problem_category_id' => $category['id'], 'name' => 'Inválida', 'system_ids' => [$this->way->id],
    ])->assertUnprocessable()->assertJsonValidationErrors('system_ids');
    $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['name' => 'Não deve salvar', 'system_ids' => [$this->way->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('system_ids');
    expect(ProblemSubcategory::findOrFail($subcategory['id'])->name)->toBe('Credenciais inválidas');
    $this->assertDatabaseCount('problem_subcategories', 1);
    $this->assertDatabaseCount('problem_subcategory_system', 1);
    expect($this->ilube->problemSubcategories()->sole()->id)->toBe($subcategory['id']);
});

test('removing parent availability disables child availability without deleting historical rows', function () {
    $ids = [$this->indicar->id, $this->ilube->id];
    $category = createProblemCategory($ids);
    $subcategory = createProblemSubcategory($category, $ids);
    $pivotIds = DB::table('problem_subcategory_system')->pluck('id')->all();
    $this->patchJson('/api/problem-categories/'.$category['id'], ['system_ids' => [$this->ilube->id]])
        ->assertOk()->assertJsonPath('data.system_ids', [$this->ilube->id])
        ->assertJsonCount(2, 'data.system_associations');
    $this->getJson('/api/problem-subcategories/'.$subcategory['id'])
        ->assertOk()->assertJsonPath('data.system_ids', [$this->ilube->id])->assertJsonPath('data.is_active', true);
    $this->assertDatabaseHas('problem_subcategory_system', ['problem_subcategory_id' => $subcategory['id'], 'system_id' => $this->indicar->id, 'is_active' => false]);
    $this->getJson('/api/problem-subcategories?system_id='.$this->indicar->id)->assertJsonCount(0, 'data');
    $this->patchJson('/api/problem-categories/'.$category['id'], ['system_ids' => $ids])->assertOk();
    // Re-enabling the parent does not silently re-enable children.
    $this->getJson('/api/problem-subcategories/'.$subcategory['id'])->assertJsonPath('data.system_ids', [$this->ilube->id]);
    $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['system_ids' => $ids])->assertOk();
    expect(DB::table('problem_subcategory_system')->pluck('id')->all())->toBe($pivotIds);
    $this->assertDatabaseCount('problem_category_system', 2);
    $this->assertDatabaseCount('problem_subcategory_system', 2);
});

test('a subcategory can remain historical with no enabled systems after parent removal', function () {
    $category = createProblemCategory([$this->indicar->id, $this->ilube->id]);
    $subcategory = createProblemSubcategory($category, [$this->indicar->id]);
    $this->patchJson('/api/problem-categories/'.$category['id'], ['system_ids' => [$this->ilube->id]])->assertOk();
    $this->getJson('/api/problem-subcategories/'.$subcategory['id'])->assertJsonPath('data.system_ids', [])->assertJsonCount(1, 'data.system_associations');
    $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['is_active' => false])->assertOk();
    $this->assertDatabaseCount('problem_subcategories', 1);
});

test('inactive systems are retained in existing associations but cannot be newly associated', function () {
    $category = createProblemCategory([$this->indicar->id, $this->ilube->id]);
    $subcategory = createProblemSubcategory($category, [$this->indicar->id]);
    $this->patchJson('/api/systems/'.$this->indicar->id, ['is_active' => false])->assertOk();
    $this->patchJson('/api/problem-categories/'.$category['id'], ['system_ids' => [$this->indicar->id, $this->ilube->id]])->assertOk();
    $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['system_ids' => [$this->indicar->id]])->assertOk();
    $this->postJson('/api/problem-categories', ['name' => 'Nova', 'system_ids' => [$this->indicar->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('system_ids');
    $this->postJson('/api/problem-subcategories', ['problem_category_id' => $category['id'], 'name' => 'Nova', 'system_ids' => [$this->indicar->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('system_ids');
    $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['system_ids' => [$this->ilube->id]])->assertOk();
    $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['system_ids' => [$this->indicar->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('system_ids');
});

test('activation changes preserve category, children and association history', function () {
    $category = createProblemCategory([$this->indicar->id]);
    $subcategory = createProblemSubcategory($category, [$this->indicar->id]);
    $associations = DB::table('problem_subcategory_system')->get()->toJson();
    foreach ([false, true] as $active) {
        $this->patchJson('/api/problem-categories/'.$category['id'], ['is_active' => $active])->assertOk()->assertJsonPath('data.is_active', $active);
        $this->getJson('/api/problem-subcategories/'.$subcategory['id'])->assertJsonPath('data.is_active', true)->assertJsonPath('data.category.is_active', $active);
        $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['is_active' => $active])->assertOk();
        $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['is_active' => true])->assertOk();
    }
    expect(DB::table('problem_subcategory_system')->get()->toJson())->toBe($associations);
    $this->assertDatabaseCount('problem_categories', 1);
    $this->assertDatabaseCount('problem_subcategories', 1);
});

test('classification lists support search, state, system, category and pagination filters without lazy loading', function () {
    $first = createProblemCategory([$this->indicar->id], 'Acesso');
    $second = createProblemCategory([$this->ilube->id], 'Busca por placa');
    $sub = createProblemSubcategory($first, [$this->indicar->id]);
    createProblemSubcategory($second, [$this->ilube->id]);
    $this->patchJson('/api/problem-categories/'.$first['id'], ['is_active' => false])->assertOk();
    Model::preventLazyLoading();
    try {
        $this->getJson('/api/problem-categories?per_page=1&page=2')->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $second['id']);
        $this->getJson('/api/problem-categories?is_active=0&q=Acesso')->assertJsonPath('data.0.id', $first['id']);
        $this->getJson('/api/problem-categories?system_id='.$this->way->id)->assertJsonCount(0, 'data');
        $this->getJson('/api/problem-subcategories?problem_category_id='.$first['id'].'&q=Credenciais&is_active=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $sub['id']);
        $this->getJson('/api/problem-subcategories?is_active=0')->assertJsonCount(0, 'data');
    } finally {
        Model::preventLazyLoading(false);
    }
});

test('classification payloads validate mandatory fields, scalar values and references', function (array $payload, string $field) {
    $this->postJson('/api/problem-categories', ['name' => 'Teste', 'system_ids' => [$this->indicar->id], ...$payload])
        ->assertUnprocessable()->assertJsonValidationErrors($field);
    $this->assertDatabaseCount('problem_categories', 0);
})->with([
    [['name' => '  '], 'name'], [['name' => []], 'name'], [['name' => str_repeat('a', 256)], 'name'],
    [['description' => []], 'description'], [['is_active' => 'invalid'], 'is_active'],
    [['system_ids' => []], 'system_ids'], [['system_ids' => null], 'system_ids'],
    [['system_ids' => '1'], 'system_ids'], [['system_ids' => [999999]], 'system_ids'],
    [['system_ids' => [1, 1]], 'system_ids.0'], [['system_ids' => ['invalid']], 'system_ids.0'],
    [['system_ids' => [[1]]], 'system_ids.0'], [['system_ids' => ['system' => 1]], 'system_ids'],
]);

test('malformed subcategory category references return validation errors', function (mixed $categoryId) {
    $this->postJson('/api/problem-subcategories', ['name' => 'Teste', 'problem_category_id' => $categoryId, 'system_ids' => [$this->indicar->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_category_id');
    $this->assertDatabaseCount('problem_subcategories', 0);
})->with([[null], [[]], [['invalid']], ['invalid']]);

test('missing classification fields and missing records use standard API errors', function () {
    $this->postJson('/api/problem-categories', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'system_ids']);
    $this->postJson('/api/problem-subcategories', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'system_ids', 'problem_category_id']);
    $this->postJson('/api/problem-subcategories', ['problem_category_id' => 999999, 'name' => 'Teste', 'system_ids' => [$this->indicar->id]])
        ->assertUnprocessable()->assertJsonValidationErrors('problem_category_id');
    foreach (['problem-categories', 'problem-subcategories'] as $endpoint) {
        $this->getJson('/api/'.$endpoint.'/999999')->assertNotFound();
        $this->patchJson('/api/'.$endpoint.'/999999', ['is_active' => false])->assertNotFound();
        $this->deleteJson('/api/'.$endpoint.'/1')->assertStatus(405);
        $this->getJson('/api/'.$endpoint.'?is_active=invalid')->assertUnprocessable();
    }
});

test('composite foreign keys reject invalid parent-system membership and mismatched ownership', function (bool $wrongOwner) {
    $category = createProblemCategory([$this->indicar->id]);
    $other = createProblemCategory([$this->way->id], 'Outra');
    $subcategory = createProblemSubcategory($category, [$this->indicar->id]);
    expect(fn () => DB::table('problem_subcategory_system')->insert([
        'problem_subcategory_id' => $subcategory['id'],
        'problem_category_id' => $wrongOwner ? $other['id'] : $category['id'],
        'system_id' => $this->way->id, 'is_active' => true,
    ]))->toThrow(QueryException::class);
    $this->assertDatabaseCount('problem_subcategory_system', 1);
})->with([true, false]);

test('classification management leaves existing records and unclassified tickets unchanged', function () {
    $contact = Contact::create(['name' => 'Ana', 'phone' => '(11) 99999-9999']);
    $customer = Customer::create(['name' => 'Loja', 'document' => '01234567000189']);
    $ticket = SupportTicket::create(['system_id' => $this->indicar->id, 'contact_id' => $contact->id, 'customer_id' => $customer->id, 'title' => 'Histórico', 'description' => 'Preservar', 'status' => 'open']);
    $before = $this->getJson('/api/support-tickets/'.$ticket->id)->assertOk()->json();
    $systemsBefore = System::all()->toJson();
    $category = createProblemCategory([$this->indicar->id, $this->ilube->id]);
    $subcategory = createProblemSubcategory($category, [$this->ilube->id]);
    $this->patchJson('/api/problem-categories/'.$category['id'], ['system_ids' => [$this->indicar->id], 'is_active' => false])->assertOk();
    $this->patchJson('/api/problem-subcategories/'.$subcategory['id'], ['is_active' => false])->assertOk();
    expect($this->getJson('/api/support-tickets/'.$ticket->id)->assertOk()->json())->toBe($before);
    expect(System::all()->toJson())->toBe($systemsBefore);
    expect($ticket->fresh()->problem_category_id)->toBeNull();
    expect($ticket->fresh()->problem_subcategory_id)->toBeNull();
    $this->assertDatabaseCount('contacts', 1);
    $this->assertDatabaseCount('customers', 1);
    $this->assertDatabaseCount('support_tickets', 1);
});
