<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceTransaction;
use Illuminate\Http\Request;
use App\Services\NodeEventPublisher;

class FinanceTransactionController extends Controller
{
    /**
     * LIST TRANSAKSI
     * GET /api/admin/transactions?month=YYYY-MM&type=income|expense&source=manual|repair|inventory
     */
    public function index(Request $request)
    {
        $month  = (string) $request->query('month', '');
        $type   = (string) $request->query('type', '');
        $source = (string) $request->query('source', '');

        $q = FinanceTransaction::query();

        if ($month !== '') {
            $q->whereRaw("DATE_FORMAT(date, '%Y-%m') = ?", [$month]);
        }

        if ($type !== '') {
            $q->where('type', $type);
        }

        if ($source !== '') {
            $q->where('source', $source);
        }

        return response()->json(
            $q->orderBy('date', 'desc')
              ->orderBy('id', 'desc')
              ->get()
        );
    }

    /**
     * CREATE TRANSACTION
     * 🔒 RULE:
     * - Income → boleh manual
     * - Expense → TIDAK BOLEH manual (harus dari repair / inventory)
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'type'     => 'required|in:income,expense',
            'category' => 'required|string',
            'amount'   => 'required|numeric|min:0.01',
            'date'     => 'required|date',
            'note'     => 'nullable|string',
            'source'   => 'nullable|in:manual,repair,inventory',
            'ref'      => 'nullable|string',
        ]);

        $source = $data['source'] ?? 'manual';

        // ❌ Expense tidak boleh manual
        if ($data['type'] === 'expense' && $source === 'manual') {
            return response()->json([
                'message' => 'Expense tidak bisa dibuat manual.'
            ], 422);
        }

        // 🔒 Expense selalu locked
        $locked = ($data['type'] === 'expense');

        $tx = FinanceTransaction::create([
            'type'     => $data['type'],
            'category' => $data['category'],
            'amount'   => $data['amount'],
            'date'     => $data['date'],
            'note'     => $data['note'] ?? null,
            'source'   => $source,
            'ref'      => $data['ref'] ?? null,
            'locked'   => $locked,
        ]);

        // 🔴 REALTIME (optional tapi rapi)
        NodeEventPublisher::publish('finance.transaction.created', [
            'transaction_id' => $tx->id,
            'type'           => $tx->type,
            'amount'         => $tx->amount,
            'source'         => $tx->source,
            'date'           => $tx->date,
        ], ['admin']);

        return response()->json($tx, 201);
    }

    /**
     * UPDATE TRANSACTION
     * 🔒 HANYA income manual yang boleh
     */
    public function update(Request $request, FinanceTransaction $financeTransaction)
    {
        if ($financeTransaction->type !== 'income') {
            return response()->json([
                'message' => 'Expense bersifat otomatis dan tidak bisa diedit.'
            ], 422);
        }

        if ($financeTransaction->locked) {
            return response()->json([
                'message' => 'Transaksi ini terkunci.'
            ], 422);
        }

        $data = $request->validate([
            'category' => 'required|string',
            'amount'   => 'required|numeric|min:0.01',
            'date'     => 'required|date',
            'note'     => 'nullable|string',
        ]);

        $financeTransaction->update($data);

        // 🔴 REALTIME
        NodeEventPublisher::publish('finance.transaction.updated', [
            'transaction_id' => $financeTransaction->id,
            'amount'         => $financeTransaction->amount,
            'date'           => $financeTransaction->date,
        ], ['admin']);

        return response()->json($financeTransaction);
    }

    /**
     * DELETE TRANSACTION
     * 🔒 HANYA income manual
     */
    public function destroy(FinanceTransaction $financeTransaction)
    {
        if ($financeTransaction->type !== 'income') {
            return response()->json([
                'message' => 'Expense bersifat otomatis dan tidak bisa dihapus.'
            ], 422);
        }

        if ($financeTransaction->locked) {
            return response()->json([
                'message' => 'Transaksi ini terkunci.'
            ], 422);
        }

        $id = $financeTransaction->id;
        $financeTransaction->delete();

        // 🔴 REALTIME
        NodeEventPublisher::publish('finance.transaction.deleted', [
            'transaction_id' => $id,
        ], ['admin']);

        return response()->json(['message' => 'Income dihapus']);
    }
}
