<?php

namespace App\Http\Controllers;

use App\Services\OnboardingService;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function __construct(private readonly OnboardingService $onboarding)
    {
    }

    public function status(Request $request)
    {
        $user = $request->user();
        $this->onboarding->maybeExpire($user, $request);
        $user->refresh();

        return response()->json($this->onboarding->status($user, $request));
    }

    public function advance(Request $request)
    {
        $status = $this->onboarding->advance($request->user(), $request);

        return response()->json($status);
    }

    public function finish(Request $request)
    {
        $this->onboarding->finish($request->user(), $request);

        return response()->json([
            'mode' => false,
            'step' => 0,
            'completed' => true,
            'target_path' => '/app',
        ]);
    }

    public function skip(Request $request)
    {
        $this->onboarding->skip($request->user(), $request);

        return response()->json([
            'mode' => false,
            'step' => 0,
            'completed' => true,
            'target_path' => '/app',
        ]);
    }

    public function replay(Request $request)
    {
        $status = $this->onboarding->replay($request->user(), $request);

        return response()->json($status);
    }
}
