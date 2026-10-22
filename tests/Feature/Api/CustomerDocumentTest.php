<?php

use App\Models\Customer;

test('customer creation requires a document', function () {
    $this->postJson('/api/customers', ['name' => 'Cliente de teste'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.document.0', 'O CPF ou CNPJ é obrigatório.');

    $this->assertDatabaseCount('customers', 0);
});

test('customer creation rejects empty documents', function (mixed $document) {
    $this->postJson('/api/customers', ['name' => 'Cliente de teste', 'document' => $document])
        ->assertUnprocessable()
        ->assertJsonPath('errors.document.0', 'O CPF ou CNPJ é obrigatório.');

    $this->assertDatabaseCount('customers', 0);
})->with([null, '', '   ', ' .-/ ']);

test('customer creation accepts and normalizes CPF and CNPJ', function (string $document, string $normalized) {
    $response = $this->postJson('/api/customers', ['name' => 'Cliente de teste', 'document' => $document])
        ->assertCreated()
        ->assertJsonPath('data.document', $normalized);

    $this->assertDatabaseHas('customers', ['id' => $response->json('data.id'), 'document' => $normalized]);
    $this->getJson('/api/customers/'.$response->json('data.id'))->assertOk()->assertJsonPath('data.document', $normalized);
})->with([
    ['01234567890', '01234567890'],
    ['01234567000189', '01234567000189'],
    [' 012.345.678-90 ', '01234567890'],
    [' 01.234.567 / 0001-89 ', '01234567000189'],
]);

test('customer creation rejects invalid document lengths and types', function (mixed $document) {
    $this->postJson('/api/customers', ['name' => 'Cliente de teste', 'document' => $document])
        ->assertUnprocessable()
        ->assertJsonPath('errors.document.0', 'Informe um CPF com 11 dígitos ou um CNPJ com 14 dígitos.');

    $this->assertDatabaseCount('customers', 0);
})->with(['1', '1234567890', '123456789012', '1234567890123', '123456789012345', '123.456.789-0', 12345678901, true, [['12345678901']]]);

test('customer documents do not require uniqueness or check digits', function () {
    foreach (range(1, 2) as $index) {
        $this->postJson('/api/customers', ['name' => 'Cliente '.$index, 'document' => '11111111111'])->assertCreated();
    }
    $this->assertDatabaseCount('customers', 2);
});

test('legacy customers without a document remain accessible and editable', function (string $method) {
    $customer = Customer::create(['name' => 'Cliente antigo', 'notes' => 'Preservar histórico']);

    $this->getJson('/api/customers')->assertOk()->assertJsonPath('data.0.id', $customer->id)->assertJsonPath('data.0.document', null);
    $this->getJson('/api/customers/'.$customer->id)->assertOk()->assertJsonPath('data.document', null);
    $this->{$method.'Json'}('/api/customers/'.$customer->id, ['name' => 'Cliente atualizado'])
        ->assertOk()->assertJsonPath('data.document', null)->assertJsonPath('data.notes', 'Preservar histórico');
    $this->{$method.'Json'}('/api/customers/'.$customer->id, ['document' => null])->assertOk()->assertJsonPath('data.document', null);
    $this->{$method.'Json'}('/api/customers/'.$customer->id, ['document' => '012.345.678-90'])
        ->assertOk()->assertJsonPath('data.document', '01234567890');
    $this->{$method.'Json'}('/api/customers/'.$customer->id, ['document' => '123'])
        ->assertUnprocessable()->assertJsonValidationErrors('document');
    expect($customer->fresh()->document)->toBe('01234567890');
})->with(['patch', 'put']);
