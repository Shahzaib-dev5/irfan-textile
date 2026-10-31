<?php

use App\Modules\Accounting\Services\FinanceService;
use App\Modules\FinishedGoods\Services\PackingService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Processing\Services\FabricFlowService;
use App\Modules\Processing\Services\ProcessingService;
use App\Modules\Production\Services\ProductionService;
use App\Modules\Purchasing\Services\PurchasingService;
use App\Modules\Sales\Services\SalesService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;

// Separate PHP process and PDO connection: never replace with sequential service calls.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.connections.mysql.database') !== 'irfan_textile_phase3_test') {
    exit(90);
}
$task = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
file_put_contents($argv[2].'.ready.'.$task['worker'], 'ready');
$deadline = microtime(true) + 60;
while (! is_file($argv[2])) {
    if (microtime(true) > $deadline) {
        exit(91);
    }
    usleep(10000);
}
try {
    $service = $app->make(InventoryService::class);
    if ($task['action'] === 'finance_confirm') {
        $app->make(FinanceService::class)->confirm($task['actor'], $task['input']);
    } elseif ($task['action'] === 'sales_reserve') {
        $app->make(SalesService::class)->reserve($task['actor'], $task['document'], $task['input']);
    } elseif ($task['action'] === 'sales_confirm') {
        $app->make(SalesService::class)->confirm($task['actor'], $task['input']);
    } elseif ($task['action'] === 'packing_confirm') {
        $app->make(PackingService::class)->confirm($task['actor'], $task['input']);
    } elseif ($task['action'] === 'production_confirm') {
        $app->make(ProductionService::class)->confirm($task['actor'], $task['input']);
    } elseif ($task['action'] === 'purchase_confirm') {
        $app->make(PurchasingService::class)->confirm($task['actor'], $task['input']);
    } elseif ($task['action'] === 'fabric_confirm') {
        $app->make(FabricFlowService::class)->execute($task['actor'], $task['input']);
    } elseif ($task['action'] === 'processing_confirm') {
        $app->make(ProcessingService::class)->confirm($task['actor'], $task['input']);
    } elseif ($task['action'] === 'processing_post') {
        $app->make(ProcessingService::class)->post($task['actor'], $task['document'], $task['key'], 0);
    } elseif ($task['action'] === 'purchase_post') {
        $app->make(PurchasingService::class)->post($task['actor'], $task['document'], $task['key'], 0);
    } elseif ($task['action'] === 'reserve') {
        $service->reserve($task['actor'], $task['document'], 0);
    } else {
        $service->post($task['actor'], $task['document'], $task['key'], 0);
    }
    $result = ['result' => 'posted'];
} catch (ValidationException $e) {
    $result = ['result' => 'rejected', 'errors' => $e->errors()];
} catch (Throwable $e) {
    $result = ['result' => 'error', 'class' => $e::class, 'message' => $e->getMessage()];
}
file_put_contents($argv[3], json_encode($result, JSON_THROW_ON_ERROR));
