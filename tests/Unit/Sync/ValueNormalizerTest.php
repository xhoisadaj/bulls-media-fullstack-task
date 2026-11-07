<?php

use App\Enums\ColumnType;
use App\Sync\ValueNormalizer;

beforeEach(fn () => $this->normalizer = new ValueNormalizer);

it('normalizes currency and separators to the same decimal', function (mixed $raw, string $formatted) {
    expect($this->normalizer->normalize(ColumnType::Decimal, $raw, $formatted)->value)->toBe('1250.5');
})->with([
    'raw number' => [1250.5, '€1,250.50'],
    'text with currency' => ['€1,250.50', '€1,250.50'],
    'european format' => ['1.250,50', '1.250,50'],
    'plain text' => ['1250.5', '1250.5'],
]);

it('treats 10, 10.0 and "10.00" as the same value', function () {
    $values = [
        $this->normalizer->normalize(ColumnType::Decimal, 10, '10')->value,
        $this->normalizer->normalize(ColumnType::Decimal, 10.0, '10.00')->value,
        $this->normalizer->normalize(ColumnType::Decimal, '10.00', '10.00')->value,
    ];

    expect(array_unique($values))->toBe(['10']);
});

it('converts Google date serial numbers', function () {
    expect($this->normalizer->normalize(ColumnType::Date, 45967, '06/11/2025')->value)->toBe('2025-11-06')
        ->and($this->normalizer->normalize(ColumnType::DateTime, 45967.5, '06/11/2025 12:00')->value)->toBe('2025-11-06 12:00:00');
});

it('parses dates typed as text', function () {
    expect($this->normalizer->normalize(ColumnType::Date, '6.11.2025', '6.11.2025')->value)->toBe('2025-11-06')
        ->and($this->normalizer->normalize(ColumnType::Date, '06.11.2025', '06.11.2025')->value)->toBe('2025-11-06')
        ->and($this->normalizer->normalize(ColumnType::Date, 'TOTAL', 'TOTAL')->ok)->toBeFalse();
});

it('keeps leading zeros for string columns by reading the displayed text', function () {
    expect($this->normalizer->normalize(ColumnType::String, 692227298, '0692227298')->value)->toBe('0692227298');
});

it('trims text and turns empty cells into null', function () {
    expect($this->normalizer->normalize(ColumnType::String, ' Bag ', ' Bag ')->value)->toBe('Bag')
        ->and($this->normalizer->normalize(ColumnType::String, '', '')->value)->toBeNull()
        ->and($this->normalizer->normalize(ColumnType::Decimal, '', '')->value)->toBeNull();
});

it('keeps case differences as real differences', function () {
    expect($this->normalizer->normalize(ColumnType::String, 'Tirana', 'Tirana')->value)
        ->not->toBe($this->normalizer->normalize(ColumnType::String, 'tirana', 'tirana')->value);
});

it('rejects text in number columns', function () {
    expect($this->normalizer->normalize(ColumnType::Integer, 'two', 'two')->ok)->toBeFalse()
        ->and($this->normalizer->normalize(ColumnType::Decimal, 'n/a', 'n/a')->ok)->toBeFalse()
        ->and($this->normalizer->normalize(ColumnType::Integer, 2.5, '2.5')->ok)->toBeFalse()
        ->and($this->normalizer->normalize(ColumnType::Integer, 3, '3')->value)->toBe(3);
});

it('reports formula errors as invalid', function (string $error) {
    $value = $this->normalizer->normalize(ColumnType::Decimal, $error, $error);

    expect($value->ok)->toBeFalse()->and($value->error)->toContain($error);
})->with(['#DIV/0!', '#REF!', '#N/A', '#VALUE!']);

it('marks IMPORTRANGE loading as temporary', function () {
    expect($this->normalizer->normalize(ColumnType::String, 'Loading...', 'Loading...')->temporary)->toBeTrue();
});

it('reads yes/no style booleans', function (mixed $raw, int $expected) {
    expect($this->normalizer->normalize(ColumnType::Boolean, $raw, is_bool($raw) ? ($raw ? 'TRUE' : 'FALSE') : (string) $raw)->value)->toBe($expected);
})->with([[true, 1], [false, 0], ['yes', 1], ['x', 1], ['', 0], ['no', 0]]);

it('extracts URLs from IMAGE formulas', function () {
    expect($this->normalizer->normalize(ColumnType::ImageUrl, '', '', '=IMAGE("https://shop.com/bag.png", 1)')->value)
        ->toBe('https://shop.com/bag.png');
});

it('rejects strings longer than 255 characters', function () {
    expect($this->normalizer->normalize(ColumnType::String, str_repeat('a', 300), str_repeat('a', 300))->ok)->toBeFalse()
        ->and($this->normalizer->normalize(ColumnType::Text, str_repeat('a', 300), str_repeat('a', 300))->ok)->toBeTrue();
});
