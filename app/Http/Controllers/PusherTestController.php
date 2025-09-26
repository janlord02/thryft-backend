<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class PusherTestController extends Controller
{
    /**
     * Test Pusher connection
     */
    public function testConnection()
    {
        try {
            // Test broadcasting
            broadcast(new \App\Events\TestEvent('Hello from Pusher!'));

            return response()->json([
                'status' => 'success',
                'message' => 'Pusher connection test successful!',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Pusher connection failed: ' . $e->getMessage(),
            ], 500);
        }
    }
}
