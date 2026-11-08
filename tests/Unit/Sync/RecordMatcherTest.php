<?php

use App\Enums\IdentityMode;
use App\Sync\Data\SheetRow;
use App\Sync\Data\StoredRecord;
use App\Sync\RecordMatcher;

function row(int $sheetRow, string $fingerprint, ?string $identity = null): SheetRow
{
    return new SheetRow($sheetRow, [], $fingerprint, $identity);
}

it('ignores moved rows in content mode', function () {
    $result = (new RecordMatcher)->match(
        [row(89, 'xhoi'), row(2, 'ana')],
        [new StoredRecord(1, 'ana'), new StoredRecord(2, 'xhoi')],
        IdentityMode::Content,
    );

    expect($result->counts())->toMatchArray(['unchanged' => 2, 'new' => 0, 'removed' => 0]);
});

it('reports an edit in content mode as removed plus new', function () {
    $result = (new RecordMatcher)->match([row(2, 'ana-40')], [new StoredRecord(1, 'ana-35')], IdentityMode::Content);

    expect($result->new)->toHaveCount(1)->and($result->removed)->toBe([1])->and($result->modified)->toBe([]);
});

it('matches identical rows by count', function () {
    $records = [new StoredRecord(1, 'bag'), new StoredRecord(2, 'bag')];

    $both = (new RecordMatcher)->match([row(5, 'bag'), row(9, 'bag')], $records, IdentityMode::Content);
    $one = (new RecordMatcher)->match([row(5, 'bag')], $records, IdentityMode::Content);

    expect($both->counts()['unchanged'])->toBe(2)->and($one->removed)->toBe([2]);
});

it('restores a soft-deleted record when the row comes back', function () {
    $result = (new RecordMatcher)->match([row(3, 'ben')], [new StoredRecord(7, 'ben', removed: true)], IdentityMode::Content);

    expect($result->restored[0]['record'])->toBe(7);
});

it('detects modifications by key, ignoring row order', function () {
    $result = (new RecordMatcher)->match(
        [row(2, 'ana-40', '1002'), row(3, 'xhoi', '1001'), row(4, 'mira', '1004')],
        [new StoredRecord(1, 'xhoi', '1001'), new StoredRecord(2, 'ana-35', '1002'), new StoredRecord(3, 'ben', '1003')],
        IdentityMode::Key,
    );

    expect($result->unchanged[0]['record'])->toBe(1)
        ->and($result->modified[0]['record'])->toBe(2)
        ->and($result->new[0]->identity)->toBe('1004')
        ->and($result->removed)->toBe([3]);
});

it('treats an edited key as removed plus new', function () {
    $result = (new RecordMatcher)->match([row(2, 'x', '1054')], [new StoredRecord(1, 'x', '1045')], IdentityMode::Key);

    expect($result->new)->toHaveCount(1)->and($result->removed)->toBe([1]);
});

it('flags duplicate keys and never removes their record', function () {
    $result = (new RecordMatcher)->match(
        [row(12, 'bag', '1002'), row(89, 'shoes', '1002')],
        [new StoredRecord(1, 'bag', '1002')],
        IdentityMode::Key,
    );

    expect($result->flagged)->toHaveCount(2)->and($result->removed)->toBe([])->and($result->unchanged)->toBe([]);
});

it('flags empty keys in key mode', function () {
    $key = (new RecordMatcher)->match([row(30, 'belt')], [], IdentityMode::Key);

    expect($key->flagged[0]['reason'])->toBe('empty key');
});

/**
 * Stored rows A..D at sheet rows 2..5, as in system ID mode.
 *
 * @return list<StoredRecord>
 */
function storedAbcd(): array
{
    return [new StoredRecord(1, 'a', sheetRow: 2), new StoredRecord(2, 'b', sheetRow: 3), new StoredRecord(3, 'c', sheetRow: 4), new StoredRecord(4, 'd', sheetRow: 5)];
}

it('pairs a row edited in place in system ID mode', function () {
    $result = (new RecordMatcher)->match([row(2, 'a'), row(3, 'b2'), row(4, 'c'), row(5, 'd')], storedAbcd(), IdentityMode::SystemId);

    expect($result->modified)->toHaveCount(1)
        ->and($result->modified[0]['record'])->toBe(2)
        ->and($result->modified[0]['by'])->toBe(RecordMatcher::BY_POSITION)
        ->and($result->new)->toBe([])->and($result->removed)->toBe([]);
});

it('pairs edits next to each other in order, and the first and last rows', function () {
    $result = (new RecordMatcher)->match([row(2, 'a2'), row(3, 'b2'), row(4, 'c2'), row(5, 'd')], storedAbcd(), IdentityMode::SystemId);

    expect(collect($result->modified)->mapWithKeys(fn ($p) => [$p['record'] => $p['row']->fingerprint])->all())
        ->toBe([1 => 'a2', 2 => 'b2', 3 => 'c2']);
});

it('pairs across inserted rows elsewhere but not when the edited row moved', function () {
    $inserted = (new RecordMatcher)->match([row(2, 'x'), row(3, 'a'), row(4, 'b'), row(5, 'c2'), row(6, 'd')], storedAbcd(), IdentityMode::SystemId);
    $moved = (new RecordMatcher)->match([row(2, 'c2'), row(3, 'a'), row(4, 'b'), row(5, 'd')], storedAbcd(), IdentityMode::SystemId);

    expect($inserted->modified[0]['record'])->toBe(3)->and($inserted->new)->toHaveCount(1)
        ->and($moved->modified)->toBe([])->and($moved->removed)->toBe([3])->and($moved->new)->toHaveCount(1);
});

it('does not pair when a gap has a different number of removed and new rows', function () {
    $result = (new RecordMatcher)->match([row(2, 'a'), row(3, 'b2'), row(4, 'd')], storedAbcd(), IdentityMode::SystemId);

    expect($result->modified)->toBe([])->and($result->removed)->toBe([2, 3])->and($result->new)->toHaveCount(1);
});

it('never pairs in content mode', function () {
    $result = (new RecordMatcher)->match([row(2, 'a'), row(3, 'b2'), row(4, 'c'), row(5, 'd')], storedAbcd(), IdentityMode::Content);

    expect($result->modified)->toBe([])->and($result->removed)->toBe([2])->and($result->new)->toHaveCount(1);
});

it('keeps identical rows on their own IDs in sheet order', function () {
    $records = [new StoredRecord(1, 'bag', sheetRow: 2), new StoredRecord(2, 'bag', sheetRow: 3), new StoredRecord(3, 'end', sheetRow: 4)];
    $result = (new RecordMatcher)->match([row(2, 'bag'), row(3, 'bag2'), row(4, 'end')], $records, IdentityMode::SystemId);

    expect($result->unchanged[0]['record'])->toBe(1)->and($result->modified[0]['record'])->toBe(2);
});
