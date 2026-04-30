<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Part;
use Illuminate\Http\Request;
use App\Services\NodeEventPublisher;

class PartController extends Controller
{
  //menampilkan daftar sparepart dengan pencarian
  public function index(Request $request)
  {
    $q = trim((string) $request->query('search', ''));

    $parts = Part::query()
      ->when($q !== '', function ($query) use ($q) {
        $query->where('name', 'like', "%{$q}%")
              ->orWhere('sku', 'like', "%{$q}%");
      })
      ->orderBy('created_at', 'desc')
      ->get();

    return response()->json($parts);
  }

  //menyimpan data sparepart baru
  public function store(Request $request)
  {
    $data = $request->validate([
      'name' => 'required|string',
      'sku' => 'required|string|unique:parts,sku',
      'stock' => 'nullable|integer|min:0',
      'min_stock' => 'nullable|integer|min:0',
      'buy_price' => 'nullable|numeric|min:0',
    ]);

    $data['sku'] = strtoupper(trim($data['sku']));
    $data['stock'] = (int) ($data['stock'] ?? 0);
    $data['min_stock'] = (int) ($data['min_stock'] ?? 0);
    $data['buy_price'] = (float) ($data['buy_price'] ?? 0);

    $part = Part::create($data);

    // publish realtime
    NodeEventPublisher::publish('part.created', [
      'part' => $part,
    ], ['admin']);

    return response()->json($part, 201);
  }

  //meng-update data sparepart
  public function update(Request $request, Part $part)
  {
    $data = $request->validate([
      'name' => 'required|string',
      'sku' => 'required|string|unique:parts,sku,' . $part->id,
      'min_stock' => 'nullable|integer|min:0',
      'buy_price' => 'nullable|numeric|min:0',
    ]);

    $data['sku'] = strtoupper(trim($data['sku']));
    $data['min_stock'] = (int) ($data['min_stock'] ?? 0);
    $data['buy_price'] = (float) ($data['buy_price'] ?? 0);

    $part->update($data);

    // publish realtime
    NodeEventPublisher::publish('part.updated', [
      'part' => $part,
    ], ['admin']);

    return response()->json($part);
  }

  //menghapus data sparepart
  public function destroy(Part $part)
  {
    $id = $part->id;
    $part->delete();

    // publish realtime
    NodeEventPublisher::publish('part.deleted', [
      'part_id' => $id,
    ], ['admin']);

    return response()->json(['message' => 'Sparepart dihapus']);
  }
}
