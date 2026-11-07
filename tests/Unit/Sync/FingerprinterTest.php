<?php

use App\Sync\Fingerprinter;

it('gives the same fingerprint regardless of key order and ignores untracked columns', function () {
    $f = new Fingerprinter;

    expect($f->fingerprint([1 => 'Bag', 2 => '35', 3 => 'note'], [2, 1]))
        ->toBe($f->fingerprint([2 => '35', 1 => 'Bag', 3 => 'other note'], [1, 2]));
});

it('cannot be fooled by separators inside values', function () {
    $f = new Fingerprinter;

    expect($f->fingerprint([1 => 'a|b', 2 => 'c'], [1, 2]))->not->toBe($f->fingerprint([1 => 'a', 2 => 'b|c'], [1, 2]));
});

it('builds identities from key columns and returns null when one is empty', function () {
    $f = new Fingerprinter;

    expect($f->identity([1 => 1001], [1]))->toBe('1001')
        ->and($f->identity([1 => '2025-01-01', 2 => 'Bag'], [1, 2]))->toBe('["2025-01-01","Bag"]')
        ->and($f->identity([1 => null], [1]))->toBeNull();
});
