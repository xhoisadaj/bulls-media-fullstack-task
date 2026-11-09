<?php

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Sync engine tests: each test runs in a transaction that is rolled back.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature/Sync');

// Admin panel tests create real tables (CREATE/ALTER/DROP TABLE). MySQL commits implicitly on DDL,
// which ends a per-test transaction, while the panel wraps every save in its own transaction.
// So these tests empty the tables between tests instead of rolling back.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->beforeEach(function () {
        // As on every real request: boot the admin panel, which also puts every action in a transaction.
        Filament::setCurrentPanel('admin');
        Filament::bootCurrentPanel();
    })
    // Empty the tables after each test too, so nothing is left for the transaction-based tests.
    // The table list is read again each time, because these tests create and drop tables.
    ->afterEach(function () {
        static::$allTables = [];
        $this->truncateTablesForAllConnections();
        static::$allTables = [];
    })
    ->in('Feature/Admin');

pest()->extend(TestCase::class)->in('Unit');
