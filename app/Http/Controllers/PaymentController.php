<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $payments = Payment::with(['user:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->limit(500)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $payments,
        ]);
    }
}


