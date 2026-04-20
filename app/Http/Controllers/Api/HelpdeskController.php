<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HelpdeskTicket;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HelpdeskController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tickets = HelpdeskTicket::query()
            ->where('customer_id', $request->user()->id)
            ->with('customerService')
            ->latest()
            ->get()
            ->map(fn (HelpdeskTicket $ticket) => PortalFormatter::helpdeskTicketSummary($ticket))
            ->values();

        return response()->json($tickets);
    }
}
