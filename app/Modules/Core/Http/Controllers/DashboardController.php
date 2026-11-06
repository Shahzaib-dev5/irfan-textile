<?php

namespace App\Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $canSales = $user->hasPermissionTo('sales.documents.view');
        $canInventory = $user->hasPermissionTo('inventory.view');
        $canFinance = $user->hasPermissionTo('accounting.finance.view');
        $months = collect(range(5, 0))->map(fn (int $offset) => Carbon::now()->startOfMonth()->subMonths($offset));
        $from = $months->first()->toDateString();
        $to = now()->toDateString();

        $salesRows = $canSales && DB::table('sales_invoices as si')->exists()
            ? DB::table('sales_invoices as si')->join('documents as d', 'd.id', '=', 'si.document_id')->where('d.status', 'posted')->whereBetween('d.document_date', [$from, $to])->selectRaw("DATE_FORMAT(d.document_date, '%Y-%m') as month, SUM(si.gross_amount) as amount")->groupBy('month')->get()->keyBy('month')
            : collect();
        $salesByMonth = $months->map(fn (Carbon $month) => ['label' => $month->format('M'), 'amount' => (float) ($salesRows[$month->format('Y-m')]->amount ?? 0)]);

        $stockByUnit = collect();
        $finishedSuits = 0.0;
        if ($canInventory) {
            $stockByUnit = DB::table('stock_movements as m')->join('documents as d', 'd.id', '=', 'm.document_id')->join('stock_positions as p', 'p.id', '=', 'm.stock_position_id')->join('stock_lots as l', 'l.id', '=', 'p.stock_lot_id')->join('items as i', 'i.id', '=', 'l.item_id')->join('units as u', 'u.id', '=', 'i.base_unit_id')->where('d.status', 'posted')->where('i.is_stocked', true)->groupBy('u.code')->orderBy('u.code')->selectRaw('u.code, SUM(m.quantity_delta) as quantity')->get();
            $finishedSuits = (float) ($stockByUnit->firstWhere('code', 'SUIT')->quantity ?? 0);
        }

        $warehouseCount = $canInventory ? DB::table('warehouses')->where('is_active', true)->count() : null;
        $cashRows = $canFinance
            ? DB::table('documents as d')->leftJoin('receipts as r', 'r.document_id', '=', 'd.id')->leftJoin('payments as p', 'p.document_id', '=', 'd.id')->where('d.status', 'posted')->whereBetween('d.document_date', [$from, $to])->where(fn ($q) => $q->whereNotNull('r.document_id')->orWhereNotNull('p.document_id'))->groupByRaw("DATE_FORMAT(d.document_date, '%Y-%m')")->selectRaw("DATE_FORMAT(d.document_date, '%Y-%m') as month, SUM(CASE WHEN r.document_id IS NOT NULL THEN r.gross_amount ELSE 0 END) as cash_in, SUM(CASE WHEN p.document_id IS NOT NULL THEN p.gross_amount ELSE 0 END) as cash_out")->get()->keyBy('month')
            : collect();
        $cashByMonth = $months->map(fn (Carbon $month) => ['label' => $month->format('M'), 'in' => (float) ($cashRows[$month->format('Y-m')]->cash_in ?? 0), 'out' => (float) ($cashRows[$month->format('Y-m')]->cash_out ?? 0)]);
        $salesTotal = $salesByMonth->sum('amount');
        $cashInTotal = $cashByMonth->sum('in');
        $cashOutTotal = $cashByMonth->sum('out');

        return view('module.core::dashboard', compact('canSales', 'canInventory', 'canFinance', 'salesByMonth', 'salesTotal', 'stockByUnit', 'finishedSuits', 'warehouseCount', 'cashByMonth', 'cashInTotal', 'cashOutTotal'));
    }
}
