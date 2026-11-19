<?php

namespace App\Modules\Warehouses\Console;

use App\Modules\Inventory\Services\InventoryGuard;
use App\Modules\Warehouses\Services\MainWarehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetDummyWarehouse extends Command
{
    protected $signature = 'warehouse:reset-dummy {--database=} {--actor=} {--backup=} {--execute}';

    protected $description = 'Preview or explicitly reset linked dummy operations and initialize Main Warehouse';

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $this->option('database') !== $database) {
            $this->error('Requires a non-production environment and the exact --database name.');

            return self::FAILURE;
        }
        $keys = collect(DB::select('SELECT TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent, REFERENCED_COLUMN_NAME AS ref FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$database]));
        $tables = ['documents', 'stock_lots', 'warehouses', 'bank_statements'];
        do {
            $before = count($tables);
            foreach ($keys as $key) {
                if (in_array($key->parent, $tables, true) && ! in_array($key->child, $tables, true) && $key->child !== 'audit_events') {
                    $tables[] = $key->child;
                }
            }
        } while (count($tables) !== $before);
        $protected = ['users', 'companies', 'company_settings', 'parties', 'party_roles', 'items', 'ledger_accounts', 'posting_accounts', 'bank_accounts', 'financial_years', 'accounting_periods', 'migrations'];
        if (array_intersect($protected, $tables)) {
            $this->error('Unexpected dependency on protected master data; reset aborted.');

            return self::FAILURE;
        }
        foreach ($tables as $table) {
            if (! preg_match('/^[a-z_]+$/D', $table)) {
                throw new \LogicException('Unexpected table identifier.');
            }
        }
        $this->table(['Database: '.$database, 'Rows to remove'], collect($tables)->map(fn ($table) => [$table, DB::table($table)->count()])->all());
        if (! $this->option('execute')) {
            $this->info('Preview only. Execution also removes document-linked audit entries; master audit history and document numbering are retained.');

            return self::SUCCESS;
        }
        $backup = realpath((string) $this->option('backup'));
        if (! $backup || ! is_file($backup) || filesize($backup) < 1000 || ! $this->option('actor')) {
            $this->error('Provide a verified database dump with --backup and an authorized --actor.');

            return self::FAILURE;
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($tables, $keys) {
                InventoryGuard::company();
                InventoryGuard::actor((int) $this->option('actor'), 'warehouses.manage');
                DB::table('audit_events')->whereNotNull('document_id')->delete();
                foreach (array_reverse($tables) as $table) {
                    DB::table($table)->delete();
                }
                app(MainWarehouse::class)->setup((int) $this->option('actor'));
                foreach ($keys as $key) {
                    $invalid = DB::table($key->child.' as c')->leftJoin($key->parent.' as p', 'c.'.$key->col, '=', 'p.'.$key->ref)->whereNotNull('c.'.$key->col)->whereNull('p.'.$key->ref)->exists();
                    if ($invalid) {
                        throw new \LogicException('Dangling reference detected in '.$key->child.'; transaction rolled back.');
                    }
                }
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->info('Dummy operations reset. Main Warehouse is ready. Restore the full backup to recover the removed data.');

        return self::SUCCESS;
    }
}
