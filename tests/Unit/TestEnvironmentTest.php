<?php

test('test bootstrap overrides every Laravel environment source before database refresh', function () {
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => ''] as $key => $expected) {
        expect(getenv($key))->toBe($expected);
        expect($_ENV[$key])->toBe($expected);
        expect($_SERVER[$key])->toBe($expected);
    }
    expect(is_file((string) getenv('APP_CONFIG_CACHE')))->toBeFalse();
});
