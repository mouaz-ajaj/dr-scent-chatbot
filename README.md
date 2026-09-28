# DR.SCENT WhatsApp AI Business Assistant

## Project purpose

A customer asks a question on WhatsApp. Laravel receives it, loads the
Business Profile plus recent conversation history, and asks Gemini for one
structured decision:

```text
WhatsApp customer question
  → Laravel
  → Gemini + Business Profile + history
  → reply  OR  silent handoff
```

- **reply**: the answer is clearly supported by the Business Profile → the bot
  sends exactly one WhatsApp reply.
- **handoff**: information is missing, incomplete, conflicting, or requires
  guessing → the bot sends **NOTHING** and the conversation becomes
  `waiting_human` until a human resumes it with
  `php artisan whatsapp:resume {phone}`.

Core principle: **if answering requires guessing, hand off silently.**

## Current MVP scope

Included:

- Business Profile answers (`storage/app/private/business_profile.json`)
- Meta WhatsApp Cloud API transport (webhook + send, TEST number only)
- Gemini structured decisions (`reply` / `handoff`)
- recent conversation history (previous 10 messages, current message separate)
- database queue (`ProcessWhatsAppMessage`, one worker, no retries)
- silent handoff (`waiting_human`) + `whatsapp:resume` command

Explicitly excluded:

- products database, orders, stock
- RAG, Knowledge Base, embeddings
- dashboard, human inbox UI
- authentication, multi-business support, analytics

## Architecture

```text
Meta webhook (signed POST)
  ↓  GET verification: hub.mode + hub.verify_token → hub.challenge
WhatsAppWebhookController
  ↓  verifies X-Hub-Signature-256 (HMAC-SHA256, raw body, hash_equals)
  ↓  parses text messages only (WhatsAppWebhookParser)
  ↓  keeps messages for the configured WHATSAPP_PHONE_NUMBER_ID
  ↓  dispatches ProcessWhatsAppMessage per message → HTTP 200
Queue (database driver, ONE worker, tries=1, timeout=60)
  ↓
ProcessWhatsAppMessage
  ├─ recipient-ID guard (fail closed on mismatch/blank config)
  ├─ find/create Conversation by sender phone (unique)
  ├─ store incoming Message (UNIQUE whatsapp_message_id = idempotency)
  ├─ waiting_human? → stop silently (no AI, no send)
  ├─ BusinessProfileService → profile (failure → silent handoff)
  ├─ previous 10 messages as history (current message excluded)
  ├─ AiService (GeminiService) → AiReplyDecision
  ├─ reply   → WhatsAppService::sendText once → store outgoing (Meta ID)
  └─ handoff → store decision, mark waiting_human, send NOTHING
```

Short DB transactions wrap DB-only writes; Gemini/Meta HTTP calls always run
outside transactions. After a confirmed Meta send the message is never sent
again, even if local persistence fails (fail closed to human handling).

## Requirements

- PHP `^8.3` (per `composer.json`), Composer
- Laravel Framework `^13.17`, MySQL
- One database queue worker (`php artisan queue:work --tries=1 --timeout=60`)
- Public HTTPS callback URL for Meta (webhook subscription)

## Local setup

```bash
composer install
copy .env.example .env        # Windows; on Linux/macOS use: cp .env.example .env
php artisan key:generate
# configure DB_* in .env, create the database, then:
php artisan migrate
php artisan serve
php artisan queue:work --tries=1 --timeout=60
```

Tests use a separate MySQL database via `.env.testing` (see
`.env.testing.example`); test files live in `tests/Feature` and `tests/Unit`.

## Gemini configuration

```env
GEMINI_API_KEY=
GEMINI_MODEL=gemini-3.5-flash-lite
```

The key is read from Laravel config (`config/services.php`), never hardcoded
or logged. All automated tests use mocked HTTP — no real Gemini calls.

Note: Google marks `responseSchema` as deprecated in favor of
`responseFormat`. The working `responseSchema` mechanism is intentionally kept
until the new contract can be verified against the live API; application-side
validation in `AiReplyDecision` remains the source of truth either way.

## WhatsApp TEST number configuration

```env
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_VERIFY_TOKEN=
WHATSAPP_APP_SECRET=
WHATSAPP_API_VERSION=
WHATSAPP_BASE_URL=https://graph.facebook.com
```

During development use the **Meta TEST number only**. Do NOT connect the
production number through these instructions.

## Webhook

Callback: `/api/webhooks/whatsapp`

- `GET` — Meta verification: `hub.mode=subscribe` + matching
  `hub.verify_token` returns `hub.challenge` (200), otherwise 403.
- `POST` — signed events only: `X-Hub-Signature-256` is verified over the
  exact raw body with the app secret before parsing; invalid/missing → 403.
  Text messages for the configured number are queued; everything else is
  acknowledged without action.

## Queue

ONE worker only for this MVP. Messages rely on DB uniqueness for idempotency
and assume sequential processing. See `docs/DEPLOYMENT.md` before scaling.

## Silent handoff

`handoff` means: no reply, no apology, no "a human will contact you", no
fallback text. The incoming message is stored with `ai_decision = handoff`,
the conversation becomes `status = waiting_human` + `needs_human = true`,
and later messages are stored silently until explicit resume.

## Resume

```bash
php artisan whatsapp:resume {phone}
```

Exact phone string, never normalized. Only this command restores automation;
new messages, time passing, sends, or restarts never auto-resume.

## Testing

```bash
php artisan test --filter=ProcessWhatsAppMessageTest
php artisan test --filter=WhatsAppWebhookTest
php artisan test --filter=GeminiServiceTest
php artisan test --filter=ConversationTest
php artisan test   # full suite (release checkpoints only)
```

## Production number warning

The real business number currently uses **WhatsApp Business App**. Production
activation will later use the official **WhatsApp Business App + Cloud API
Coexistence** flow. Do NOT perform full migration/deregistration unless
explicitly approved. See `docs/DEPLOYMENT.md`.
