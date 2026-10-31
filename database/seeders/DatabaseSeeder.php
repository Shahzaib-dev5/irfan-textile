<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CurrencySeeder::class,
            PartyConfigurationSeeder::class,
            CatalogConfigurationSeeder::class,
            IdentityAuthorizationSeeder::class,
            InventoryConfigurationSeeder::class,
            PurchasingConfigurationSeeder::class,
            ProcessingConfigurationSeeder::class,
            ProcessingFabricSetupSeeder::class,
            ProductionConfigurationSeeder::class,
            FinishedGoodsConfigurationSeeder::class,
            SalesConfigurationSeeder::class,
            FinanceConfigurationSeeder::class,
        ]);
    }
}
