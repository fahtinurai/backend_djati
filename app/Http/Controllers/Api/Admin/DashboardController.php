<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\DamageReport;
use App\Models\Part;
use App\Models\FinanceTransaction;

class DashboardController extends Controller
{
    public function index()
    {
        return response()->json([
            // jumlah driver
            'drivers' => User::where('role', 'driver')->count(),

            // jumlah teknisi
            'technicians' => User::where('role', 'technician')->count(),

            // jumlah kendaraan
            'vehicles' => Vehicle::count(),

            // follow-up yang butuh admin
            'followups' => DamageReport::whereHas(
                'latestTechnicianResponse',
                fn ($q) => $q->where('status', 'butuh_followup_admin')
            )->count(),

            // jumlah sparepart
            'parts' => Part::count(),

            // jumlah transaksi keuangan
            'transactions' => FinanceTransaction::count(),
        ]);
    }
}
