<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

//menampilkan histori pergerakan stok
class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $limit = (int) $request->query('limit', 50);
        $limit = max(1, min($limit, 200));

        $rows = StockMovement::with('part:id,name,sku')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return response()->json($rows);
    }

    // Hanya IN dari halaman Inventory
    public function store(Request $request)
    {
        $data = $request->validate([
            'part_id' => 'required|exists:parts,id',
            'type' => 'required|in:IN',
            'qty' => 'required|integer|min:1',
            'note' => 'nullable|string',

            // ini yang sesuai migration
            'date' => 'nullable|date',

            'ref' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($data) {
            /** @var Part $part */
            $part = Part::lockForUpdate()->findOrFail($data['part_id']);

            $part->stock = $part->stock + (int) $data['qty'];
            $part->save();

            $move = StockMovement::create([
                'part_id' => $part->id,
                'type' => 'IN',
                'qty' => (int) $data['qty'],
                'note' => $data['note'] ?? null,

                // simpan ke kolom date
                'date' => $data['date'] ?? now()->toDateString(),

                'ref' => $data['ref'] ?? null,
            ]);

            // biar response langsung punya sparepart untuk UI
            $move->load('part:id,name,sku');

            return response()->json([
                'message' => 'Stok masuk berhasil',
                'part' => $part,
                'movement' => $move,
            ], 201);
        });
    }
}
