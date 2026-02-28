<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

class WebauthnController extends Controller
{
    public function status(Request $request)
    {
        return response()->json([
            'enabled' => $request->user()->webAuthnCredentials()->whereEnabled()->exists(),
        ]);
    }

    public function registerOptions(AttestationRequest $request)
    {
        $options = $request->secureRegistration()->toCreate();

        if (is_object($options) && method_exists($options, 'toArray')) {
            $options = $options->toArray();
        }

        if (is_array($options)) {
            $options['excludeCredentials'] = [];
            if (isset($options['publicKey']) && is_array($options['publicKey'])) {
                $options['publicKey']['excludeCredentials'] = [];
            }
        }

        return $options;
    }

    public function registerVerify(AttestedRequest $request)
    {
        try {
            $request->save([
                'alias' => 'Penny Passkey',
            ]);

            return response()->json([
                'enabled' => true,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Passkey didn’t work this time. You can sign in another way.',
            ], 422);
        }
    }

    public function authenticateOptions(AssertionRequest $request)
    {
        return $request->secureLogin()->toVerify(null);
    }

    public function authenticateVerify(AssertedRequest $request)
    {
        try {
            $user = $request->login();

            if (! $user) {
                return response()->json([
                    'message' => 'Passkey didn’t work this time. You can sign in another way.',
                ], 422);
            }

            return response()->json([
                'user' => $user,
                'csrf_token' => csrf_token(),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Passkey didn’t work this time. You can sign in another way.',
            ], 422);
        }
    }

    public function disable(Request $request)
    {
        $request->user()->disableAllCredentials();

        return response()->json([
            'enabled' => false,
        ]);
    }
}
