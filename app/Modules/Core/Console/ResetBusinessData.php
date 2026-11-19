<?php

namespace App\Modules\Core\Console;

use App\Models\BankAccount;
use App\Models\LedgerAccount;
use App\Models\PostingAccount;
use App\Modules\Inventory\Services\InventoryGuard;
use App\Modules\Warehouses\Services\MainWarehouse;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetBusinessData extends Command
{
    protected $signature = 'erp:reset-business {--database=} {--backup=} {--actor=} {--execute}';

    protected $description = 'Preview or reset local ERP business data while retaining company and setup';

    private const KEEP = [
        'migrations', 'companies', 'company_settings', 'users', 'access_roles',
        'permissions', 'role_permissions', 'user_roles', 'currencies', 'units',
        'item_categories', 'party_role_types', 'payment_terms', 'processing_services',
        'document_types', 'posting_accounts', 'financial_years', 'accounting_periods',
        'warehouses', 'warehouse_locations', 'ledger_accounts', 'bank_accounts',
    ];

    public function handle(): int
    {
        $database = DB::connection()->getDatabaseName();
        if (app()->environment('production') || $this->option('database') !== $database) {
            $this->error('Requires a non-production environment and the exact database name.');

            return self::FAILURE;
        }

        $tables = collect(DB::select('SELECT TABLE_NAME AS name FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?', [$database, 'BASE TABLE']))
            ->pluck('name')->all();
        $missing = array_diff(self::KEEP, $tables);
        if ($missing || ! in_array('documents', $tables, true)) {
            $this->error('Unexpected database schema. Reset aborted.');

            return self::FAILURE;
        }
        $wipe = array_values(array_diff($tables, self::KEEP));
        foreach ($tables as $table) {
            if (! preg_match('/^[a-z_]+$/D', $table)) {
                throw new \LogicException('Unexpected table identifier: '.$table);
            }
        }
        $this->table(['Area', 'Current rows'], [
            ['Documents', DB::table('documents')->count()],
            ['Parties', DB::table('parties')->count()],
            ['Items', DB::table('items')->count()],
            ['Other business tables', count($wipe)],
            ['Company profiles retained', DB::table('companies')->count()],
            ['Users retained', DB::table('users')->count()],
        ]);
        if (! $this->option('execute')) {
            $this->info('Preview only. Execution also removes audit and login sessions.');

            return self::SUCCESS;
        }
        $backup = realpath((string) $this->option('backup'));
        if (! $backup || ! is_file($backup) || filesize($backup) < 1000 || ! $this->option('actor')) {
            $this->error('Verified backup and authorized actor are required.');

            return self::FAILURE;
        }
        InventoryGuard::actor((int) $this->option('actor'), 'warehouses.manage');
        InventoryGuard::actor((int) $this->option('actor'), 'accounting.finance.configure');
        $cash = BankAccount::where('code', 'MAIN-CASH')->where('account_kind', 'cash')->firstOrFail();
        $mainWarehouse = DB::table('warehouses')->where('code', 'MAIN')->value('id');
        $mainLocation = DB::table('warehouse_locations')->where('warehouse_id', $mainWarehouse)->where('code', 'MAIN')->value('id');
        if (! $mainWarehouse || ! $mainLocation) {
            $this->error('Main warehouse storage is missing. Reset aborted.');

            return self::FAILURE;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            DB::transaction(function () use ($wipe, $cash, $mainWarehouse, $mainLocation, $database) {
                InventoryGuard::company();
                foreach ($wipe as $table) {
                    DB::table($table)->delete();
                }
                DB::table('warehouse_locations')->where('id', '<>', $mainLocation)->delete();
                DB::table('warehouses')->where('id', '<>', $mainWarehouse)->delete();
                DB::table('bank_accounts')->where('id', '<>', $cash->id)->delete();
                $ledgerIds = PostingAccount::pluck('ledger_account_id')->push($cash->ledger_account_id)->unique()->all();
                LedgerAccount::whereNotIn('id', $ledgerIds)->delete();
                app(MainWarehouse::class)->setup((int) $this->option('actor'));

                $keys = DB::select('SELECT TABLE_NAME AS child, COLUMN_NAME AS col, REFERENCED_TABLE_NAME AS parent, REFERENCED_COLUMN_NAME AS ref FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$database]);
                foreach ($keys as $key) {
                    $invalid = DB::table($key->child.' as c')->leftJoin($key->parent.' as p', 'c.'.$key->col, '=', 'p.'.$key->ref)
                        ->whereNotNull('c.'.$key->col)->whereNull('p.'.$key->ref)->exists();
                    if ($invalid) {
                        throw new \LogicException('Dangling reference in '.$key->child.'. Reset rolled back.');
                    }
                }
            });
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
        $this->info('Business data removed. Company setup and Main Warehouse retained.');

        return self::SUCCESS;
    }
}
