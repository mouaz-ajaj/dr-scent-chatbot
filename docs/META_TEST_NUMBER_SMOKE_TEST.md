# Meta TEST-Number Live Smoke Test (manual, next phase)

> DO NOT use the production WhatsApp Business number during this checklist.
> Everything below uses Meta's TEST number only.

## What we need from Meta

Meta Developer Dashboard (App with the WhatsApp product):

- Meta App + WhatsApp product enabled
- TEST Phone Number ID → `WHATSAPP_PHONE_NUMBER_ID`
- TEST / temporary access token → `WHATSAPP_ACCESS_TOKEN`
- App Secret → `WHATSAPP_APP_SECRET`
- A self-chosen random Verify Token → `WHATSAPP_VERIFY_TOKEN`
- Current Graph API version → `WHATSAPP_API_VERSION`
- An allowed test recipient (your own phone, added as a test recipient)
- Public HTTPS callback URL (see tunnel note)
- `messages` webhook-field subscription on the callback

Gemini:

- `GEMINI_API_KEY`
- `GEMINI_MODEL` (default `gemini-3.5-flash-lite` is fine)

## Local tunnel (environment setup only)

Meta must reach the app over HTTPS. Use an external tunnel — Cloudflare
Tunnel OR ngrok — installed and run outside this project (no tunneling
packages belong in the Laravel app). Point it at `php artisan serve`:

Callback:

```text
https://<public-host>/api/webhooks/whatsapp
```

Verify in the Meta dashboard that token verification succeeds
(`hub.mode=subscribe` handshake). Do not commit tunnel URLs or credentials.

## Run the worker

```bash
php artisan queue:work --tries=1 --timeout=60
```

ONE worker only.

## TEST A — supported information

Ask something clearly present in the Business Profile (e.g. working hours,
service areas, rental availability).

Expected:

- incoming message stored
- Gemini called
- exactly one WhatsApp reply sent
- outgoing message stored with Meta's message ID
- conversation stays `active`

## TEST B — missing information

Ask about a price, payment method, warranty, or delivery detail that the
Business Profile does not contain.

Expected:

- incoming stored
- AI decision `handoff`
- **0** outgoing WhatsApp messages (check the test recipient phone!)
- conversation becomes `waiting_human` / `needs_human = true`

## TEST C — message after handoff

Send another customer message while `waiting_human`.

Expected:

- incoming stored
- **0** Gemini calls (check logs: no AI request)
- **0** outgoing messages

## TEST D — resume

```bash
php artisan whatsapp:resume <phone>
```

Then send a supported question (TEST A style).

Expected:

- command confirms success
- AI replies again (conversation `active`)

## TEST E — duplicate webhook

If safely reproducible locally (e.g. replay the same signed payload twice):

Expected:

- one incoming row for the WhatsApp message ID
- at most one AI call and at most one reply for it

> Explicit warning: never run any of these scenarios against the production
> WhatsApp Business number. Production activation follows the Coexistence
> checklist in `docs/DEPLOYMENT.md` only after this smoke test passes.
