<?php

namespace App\Http\Controllers;

use App\Services\WhatsApp\WhatsAppWebhookParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WhatsAppWebhookController extends Controller
{
    /**
     * Meta webhook verification handshake.
     *
     * Returns the challenge only when hub.mode is "subscribe" and the verify
     * token securely matches our configured token. Never logs the token.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub.mode');
        $token = $request->query('hub.verify_token');
        $challenge = $request->query('hub.challenge');
        $expected = config('services.whatsapp.verify_token');

        if ($mode !== 'subscribe'
            || ! is_string($token) || $token === ''
            || ! is_string($challenge) || $challenge === ''
            || ! is_string($expected) || trim($expected) === ''
            || ! hash_equals($expected, $token)
        ) {
            return response('Forbidden', 403);
        }

        return response($challenge, 200);
    }

    /**
     * Receive a Meta webhook event.
     *
     * Phase 3 transport boundary: verify the request signature, parse supported
     * text messages, and acknowledge Meta. No AI, no replies, no conversation
     * state changes — Phase 4 consumes the parsed messages.
     */
    public function receive(Request $request, WhatsAppWebhookParser $parser): Response
    {
        $secret = config('services.whatsapp.app_secret');

        if (! is_string($secret) || trim($secret) === '' || ! $this->hasValidSignature($request, $secret)) {
            Log::warning('WhatsApp webhook rejected: invalid signature.');

            return response('Forbidden', 403);
        }

        $payload = $request->json()->all();

        $messages = $parser->parse(is_array($payload) ? $payload : []);

        return response()->json(['received' => count($messages)]);
    }

    /**
     * Verify X-Hub-Signature-256 over the exact raw request body.
     */
    private function hasValidSignature(Request $request, string $secret): bool
    {
        $header = $request->header('X-Hub-Signature-256');

        if (! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $provided = substr($header, strlen('sha256='));

        if ($provided === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $provided);
    }
}
