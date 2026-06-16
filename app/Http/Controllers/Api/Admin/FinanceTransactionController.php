<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FinanceTransaction;
use Illuminate\Http\Request;
use App\Services\NodeEventPublisher;
use Carbon\Carbon;

class FinanceTransactionController extends Controller
{
    /**
     * Timezone aplikasi.
     * Dipakai agar tanggal transaksi tidak bergeser karena UTC/server timezone.
     */
    private string $timezone = 'Asia/Jakarta';

    /**
     * NORMALISASI TANGGAL
     *
     * Tujuan:
     * - Jika frontend mengirim YYYY-MM-DD, tanggal disimpan tetap sesuai input.
     * - Jika frontend mengirim ISO/UTC, tanggal dikonversi dulu ke Asia/Jakarta.
     * - Database menyimpan tanggal dalam format Y-m-d agar stabil.
     */
    private function normalizeDate(string $value): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::createFromFormat('Y-m-d', $value, $this->timezone)
                ->toDateString();
        }

        return Carbon::parse($value)
            ->setTimezone($this->timezone)
            ->toDateString();
    }

    /**
     * LIST TRANSAKSI
     *
     * GET /api/admin/transactions?month=YYYY-MM&type=income|expense&source=manual|repair|inventory
     */
    public function index(Request $request)
    {
        $month  = trim((string) $request->query('month', ''));
        $type   = strtolower(trim((string) $request->query('type', '')));
        $source = strtolower(trim((string) $request->query('source', '')));

        if (in_array($type, ['all', 'semua', 'semua jenis'], true)) {
            $type = '';
        }

        if (in_array($source, ['all', 'semua', 'semua sumber'], true)) {
            $source = '';
        }

        $q = FinanceTransaction::query();

        /**
         * FILTER BULAN
         *
         * Sebelumnya:
         * DATE_FORMAT(date, '%Y-%m')
         *
         * Diganti menjadi range tanggal agar lebih aman
         * dan tidak rawan error ketika date berbentuk datetime/timestamp.
         */
        if ($month !== '') {
            if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
                return response()->json([
                    'message' => 'Format month harus YYYY-MM, contoh: 2026-06.'
                ], 422);
            }

            $start = Carbon::createFromFormat('Y-m', $month, $this->timezone)
                ->startOfMonth();

            $nextMonth = $start->copy()->addMonth();

            $q->where('date', '>=', $start->toDateString())
              ->where('date', '<', $nextMonth->toDateString());
        }

        if ($type !== '') {
            $q->where('type', $type);
        }

        if ($source !== '') {
            $q->where('source', $source);
        }

        $rows = $q->orderBy('date', 'desc')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($tx) {
                /**
                 * PENTING:
                 * Ambil tanggal asli dari database.
                 * Jangan pakai hasil cast Carbon karena bisa berubah ke UTC
                 * dan tampil maju/mundur 1 hari di frontend.
                 */
                $tx->date = $tx->getRawOriginal('date');

                return $tx;
            });

        return response()->json($rows);
    }

    /**
     * CREATE TRANSACTION
     *
     * RULE:
     * - Income boleh manual
     * - Expense tidak boleh manual
     * - Expense harus berasal dari repair / inventory
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

        /**
         * Expense tidak boleh dibuat manual.
         */
        if ($data['type'] === 'expense' && $source === 'manual') {
            return response()->json([
                'message' => 'Expense tidak bisa dibuat manual.'
            ], 422);
        }

        /**
         * Expense selalu locked.
         * Income manual tidak locked.
         */
        $locked = ($data['type'] === 'expense');

        /**
         * Normalisasi tanggal agar tidak bergeser.
         */
        $date = $this->normalizeDate($data['date']);

        $tx = FinanceTransaction::create([
            'type'     => $data['type'],
            'category' => $data['category'],
            'amount'   => $data['amount'],
            'date'     => $date,
            'note'     => $data['note'] ?? null,
            'source'   => $source,
            'ref'      => $data['ref'] ?? null,
            'locked'   => $locked,
        ]);

        /**
         * Paksa tanggal response memakai tanggal asli database.
         */
        $tx->date = $tx->getRawOriginal('date');

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
     *
     * Hanya income yang boleh diedit.
     * Expense otomatis tidak boleh diedit.
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

        /**
         * Normalisasi tanggal saat update juga.
         */
        $data['date'] = $this->normalizeDate($data['date']);

        $financeTransaction->update($data);
        $financeTransaction->refresh();

        /**
         * Paksa tanggal response memakai tanggal asli database.
         */
        $financeTransaction->date = $financeTransaction->getRawOriginal('date');

        NodeEventPublisher::publish('finance.transaction.updated', [
            'transaction_id' => $financeTransaction->id,
            'amount'         => $financeTransaction->amount,
            'date'           => $financeTransaction->date,
        ], ['admin']);

        return response()->json($financeTransaction);
    }

    /**
     * DELETE TRANSACTION
     *
     * Hanya income manual yang boleh dihapus.
     * Expense otomatis tidak boleh dihapus.
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

        NodeEventPublisher::publish('finance.transaction.deleted', [
            'transaction_id' => $id,
        ], ['admin']);

        return response()->json([
            'message' => 'Income dihapus'
        ]);
    }
}