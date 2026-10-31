<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationMatch;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\Document;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Party;
use App\Models\PartyRole;
use App\Models\PurchaseInvoice;
use App\Models\PurchaseInvoiceReceiptMatch;
use App\Models\PurchaseReturnLine;
use App\Models\SettlementAllocation;
use App\Modules\Accounting\Http\Requests\FinanceRequest;
use App\Modules\Accounting\Services\BankReconciliationService;
use App\Modules\Accounting\Services\FinanceAccounts;
use App\Modules\Accounting\Services\FinanceDocument;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\FinanceService;
use App\Modules\Accounting\Services\SupplierCreditService;
use App\Modules\Core\Services\TransactionDocumentRenderer;
use App\Modules\Inventory\Services\InventoryGuard;
use App\Policies\FinancePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FinanceController extends Controller
{
    public function supplierCredit(Request $request)
    {
        $request->validate(['invoice' => 'nullable|integer|exists:purchase_invoices,document_id']);
        $invoices = PurchaseInvoice::with('document', 'supplier.party')->whereHas('document', fn ($q) => $q->where('status', 'posted'))->orderByDesc('document_id')->get();
        $invoice = $request->filled('invoice') ? PurchaseInvoice::with('lines')->findOrFail($request->integer('invoice')) : null;
        $matches = $invoice ? PurchaseInvoiceReceiptMatch::whereIn('purchase_invoice_line_id', $invoice->lines->pluck('id'))->get() : collect();
        $returns = PurchaseReturnLine::with('document')->whereIn('goods_receipt_line_id', $matches->pluck('goods_receipt_line_id'))->whereHas('document', fn ($q) => $q->where('status', 'posted'))->get();

        return view('module.accounting::finance.supplier-credit', compact('invoices', 'invoice', 'matches', 'returns'));
    }

    public function supplierCreditStore(Request $request, SupplierCreditService $service)
    {
        $doc = $service->confirm($request->user()->id, $request->all());

        return redirect()->route('finance.show', $doc)->with('status', 'Supplier credit confirmed. Apply its debit open item against the bill or retain it for a refund.');
    }

    public function pdf(Request $request, Document $document, FinanceDocument $source, TransactionDocumentRenderer $renderer)
    {
        return $renderer->render($source->data($document), $request->route('format') === 'print');
    }

    public function index(Request $request)
    {
        $filters = $request->validate(['search' => 'nullable|string|max:100']);
        $documents = Document::with('type', 'journal')->where(fn ($q) => $q->whereHas('journal')->orWhereHas('type', fn ($q) => $q->where('module_code', 'accounting')))->when($filters['search'] ?? null, fn ($q, $s) => $q->where('number', 'like', '%'.$s.'%'))->orderByDesc('id')->paginate(30)->withQueryString();

        return view('module.accounting::finance.index', compact('documents'));
    }

    public function create(Request $request)
    {
        $kind = $request->validate(['kind' => 'required|in:journal,cash_journal,opening,payment,receipt,settlement,reversal'])['kind'];
        $permission = match ($kind) {
            'opening' => 'accounting.finance.opening','reversal' => 'accounting.finance.reverse',default => 'accounting.finance.post'
        };
        abort_unless($request->user()->hasPermissionTo($permission), 403);
        $accounts = LedgerAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')->get();
        $banks = BankAccount::where('is_active', true)->orderBy('name')->get();
        $roles = PartyRole::with('party', 'type')->where('is_active', true)->whereHas('type', fn ($q) => $q->whereIn('code', ['supplier', 'vendor', 'customer']))->get();

        $openItems = app(FinanceReports::class)->availableItems();

        if (in_array($kind, ['payment', 'receipt', 'settlement']) && ! $request->boolean('advanced')) {
            $guide = [
                'kind' => $kind,
                'roles' => $roles->map(fn ($r) => ['id' => (string) $r->id, 'name' => $r->party->legal_name.' — '.$r->type->name, 'customer' => $r->type->code === 'customer'])->values(),
                'accounts' => $accounts->whereIn('control_type', ['party_payable', 'party_receivable'])->map(fn ($a) => ['id' => (string) $a->id, 'name' => $a->code.' — '.$a->name, 'customer' => $a->control_type === 'party_receivable'])->values(),
                'items' => $openItems->map(fn ($i) => ['id' => (string) $i->id, 'role' => (string) $i->journalLine->party_role_id, 'account' => (string) $i->journalLine->ledger_account_id, 'debit' => bccomp($i->journalLine->debit, '0', 4) > 0, 'amount' => $i->outstanding, 'number' => $i->journalLine->document->number, 'date' => $i->journalLine->document->document_date->format('Y-m-d')])->values(),
                'banks' => $banks->map(fn ($b) => ['id' => (string) $b->id, 'kind' => $b->account_kind])->values(),
                'old' => $request->session()->getOldInput(),
            ];

            return view('module.accounting::finance.guided', compact('kind', 'banks', 'guide'));
        }

        return view('module.accounting::finance.form', compact('kind', 'accounts', 'banks', 'roles', 'openItems'));
    }

    public function store(FinanceRequest $request, FinanceService $service)
    {
        $doc = $service->confirm($request->user()->id, $request->validated());

        return redirect()->route('finance.show', $doc)->with('status', 'Confirmed once. Financial history is retained.');
    }

    public function show(Document $document)
    {
        abort_unless(app(FinancePolicy::class)->view(auth()->user(), $document), 403);
        $document->load('type', 'journal.lines.account', 'journal.lines.party', 'events');
        $allocations = SettlementAllocation::where('document_id', $document->id)->get();
        $reconciliation = BankReconciliation::find($document->id);
        $matches = BankReconciliationMatch::where('document_id', $document->id)->get();

        return view('module.accounting::finance.show', compact('document', 'allocations', 'reconciliation', 'matches'));
    }

    public function accounts(Request $request)
    {
        $request->validate(['edit' => 'nullable|integer|exists:ledger_accounts,id']);
        $accounts = LedgerAccount::orderBy('code')->get();
        $edit = $request->filled('edit') ? LedgerAccount::findOrFail($request->integer('edit')) : null;
        $banks = BankAccount::orderBy('code')->get();

        return view('module.accounting::finance.accounts', compact('accounts', 'edit', 'banks'));
    }

    public function accountSave(Request $request, FinanceAccounts $service)
    {
        $request->validate(['id' => 'nullable|integer|exists:ledger_accounts,id']);
        $service->save($request->user()->id, $request->all(), $request->filled('id') ? $request->integer('id') : null);

        return back()->with('status', 'Account saved with an audit record.');
    }

    public function bankSave(Request $request, FinanceAccounts $service)
    {
        $service->bank($request->user()->id, $request->all());

        return back()->with('status', 'Cash / bank account configured.');
    }

    public function report(Request $request, FinanceReports $reports)
    {
        $filters = $request->validate(['report' => 'nullable|in:trial,profit,balance,ledger,party,open,reconciliation', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'account' => 'nullable|integer|exists:ledger_accounts,id', 'party' => 'nullable|integer|exists:parties,id', 'control' => 'nullable|in:party_payable,party_receivable']);
        $type = $filters['report'] ?? 'trial';
        $from = $filters['from'] ?? now()->startOfYear()->format('Y-m-d');
        $to = $filters['to'] ?? InventoryGuard::businessDate();
        $result = DB::transaction(fn () => match ($type) {
            'trial','balance' => $reports->trial($to),'profit' => $reports->profit($from, $to),'ledger','party' => $reports->ledger($from, $to, $filters['account'] ?? null, $filters['party'] ?? null),'open' => $reports->openItems($to, $filters['party'] ?? null, $filters['control'] ?? null),default => $reports->integrity($to)
        });
        $accounts = LedgerAccount::orderBy('code')->get();
        $parties = Party::orderBy('legal_name')->get();

        return view('module.accounting::finance.report', compact('type', 'from', 'to', 'result', 'accounts', 'parties'));
    }

    public function bank(Request $request)
    {
        $request->validate(['statement' => 'nullable|integer|exists:bank_statements,id']);
        $banks = BankAccount::orderBy('name')->get();
        $statements = BankStatement::orderByDesc('id')->paginate(30);
        $statement = $request->filled('statement') ? BankStatement::findOrFail($request->integer('statement')) : null;
        $statementLines = $statement ? BankStatementLine::where('bank_statement_id', $statement->id)->get() : collect();
        $bank = $statement ? BankAccount::findOrFail($statement->bank_account_id) : null;
        $journalLines = $bank ? JournalLine::with('document')->where('ledger_account_id', $bank->ledger_account_id)->whereHas('document', fn ($q) => $q->where('status', 'posted')->whereDate('document_date', '<=', $statement->ends_on))->orderByDesc('id')->get() : collect();

        return view('module.accounting::finance.bank', compact('banks', 'statements', 'statement', 'statementLines', 'journalLines'));
    }

    public function statementSave(Request $request, BankReconciliationService $service)
    {
        $s = $service->statement($request->user()->id, $request->all());

        return redirect()->route('finance.bank', ['statement' => $s->id])->with('status', 'Statement evidence confirmed. Match its transactions below.');
    }

    public function reconcile(Request $request, BankReconciliationService $service)
    {
        $doc = $request->input('action') === 'reverse' ? $service->reverse($request->user()->id, $request->all()) : $service->confirm($request->user()->id, $request->all());

        return redirect()->route('finance.show', $doc)->with('status', 'Reconciliation confirmed.');
    }
}
