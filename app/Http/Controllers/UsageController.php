<?php

namespace App\Http\Controllers;

use App\Services\PlanUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UsageController extends Controller
{
    public function __construct(private readonly PlanUsageService $planUsage)
    {
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->planUsage->usageSummary($request->user()));
    }
}
