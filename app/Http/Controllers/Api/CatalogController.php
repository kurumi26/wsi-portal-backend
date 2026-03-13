<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Support\PortalFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $services = Service::query()
            ->where('is_active', true)
            ->with(['configurations', 'addons'])
            ->orderBy('name')
            ->get()
            ->map(fn (Service $service) => PortalFormatter::service($service))
            ->values();

        return response()->json($services);
    }
}
