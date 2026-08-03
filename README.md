# Tupay Backend Engine

This repository contains a production-ready, high-performance financial swap engine built with Laravel 10.

Here is a breakdown of how I solved the challenges and met every requirement of the assessment.

## Architectural Decisions & Solutions

### 1. The Step-Up Security Pattern (Action-Hashed 2FA)

I wanted to ensure that even if an attacker intercepts a TOTP code, they can't use it to drain a wallet.

- **The Hashing Mechanism:** When a user passes the 2FA challenge, I don't just give them a generic token. I take their exact intended action (e.g., swapping 150M kobo from Wallet A to B) and hash it using `hash('sha256', json_encode($action_payload))`.
- **The Elevated Action Token (EAT):** I generate an EAT and store it in Redis with a strict 60-second TTL. The Redis key binds the token to that exact payload hash. If the user changes even one kobo in their request, the hash changes, and the token becomes instantly invalid.
- **Atomic Invalidation:** To prevent replay attacks, the `VerifyElevatedActionToken` middleware uses a custom **Redis LUA Script** to read and delete the token in one atomic swoop. Even if a bot fires 1,000 identical requests at the exact same millisecond, Redis guarantees that exactly _one_ request gets the token, and the other 999 are rejected with a `401 Unauthorized`.

### 2. Deadlock Prevention & The Swap Engine

When two people try to swap money at the same time, database locks can easily collide and cause a distributed deadlock.

- **Lexicographical Lock Ordering:** Before I even touch the database, I gather the User ID and both Wallet IDs, and I sort them alphabetically. By ensuring every server acquires Redis locks in the exact same deterministic order, deadlocks are mathematically impossible.
- **The Lock Sequence:**
    1. Acquire the sorted Redis locks (blocking for up to 5 seconds).
    2. Open a MySQL transaction with the `REPEATABLE READ` isolation level.
    3. Apply a pessimistic row-level lock (`SELECT ... FOR UPDATE`) on the specific wallets.
    4. Perform the balance checks and ledger inserts.
    5. Safely release the Redis locks inside a `finally` block.
- **Dynamic Slippage & Math:** I used `bcmath` for all internal calculations to ensure absolute subunit precision. I apply the dynamic tiered slippage fee (0.5% base + 0.1% per additional 500k NGN) and use Banker's Rounding (`PHP_ROUND_HALF_EVEN`) to safely convert back to integers.

### 3. The Pure Double-Entry Ledger

I treat the database as the absolute ultimate source of truth.

- **Zero-Sum Ledger:** Every transaction creates two paired `ledger_entries` (a Debit and a Credit). The system is perfectly balanced; money is never created or destroyed, only moved.
- **No Static Balances:** The `wallets` table does not have a hardcoded `balance` column. Balances are derived dynamically from the ledger entries.
- **Deep-Storage Guardrails:** I added a MySQL `BEFORE INSERT` trigger directly to the database. Even if someone manages to bypass the PHP codebase entirely, the database itself will abort any transaction that would push a wallet's total below zero.
- **Indexing Rationale:** To ensure the `GET /api/ledger/{wallet_id}` endpoint remains lightning-fast even with millions of records, I added a composite index on `(wallet_id, created_at DESC)`. This allows the database to skip full table scans and instantly paginate a user's chronological history in $O(\log n)$ time.

### 4. Idempotent Settlement Webhooks

- **Security:** Incoming webhooks are protected by HMAC-SHA256 signature middleware.
- **Resiliency:** Payment providers aren't always perfect. If a `COMPLETED` webhook arrives _before_ an `INITIATED` webhook in the hypothical scenerio i made up, our state machine gracefully handles the out-of-order delivery without corrupting the ledger.
- **Performance:** Heavy webhook processing is offloaded to Redis Queue workers so the API can respond to the provider immediately.

---

## Getting Started

To spin up the engine, follow these steps:

### 1. Prerequisites

- PHP 8.2+ with the `php-bcmath` extension installed (`sudo apt install php-bcmath`).
- A local Redis server running on the default port.
- `predis/predis` (installed via Composer) configured as the Laravel Redis client (`REDIS_CLIENT=predis`).
- MySQL or PostgreSQL.

### 2. Setup

```bash
# Install dependencies
composer install

# Set up your environment variables
cp .env.example .env
php artisan key:generate
```

Then, open your `.env` file and ensure the following core variables are set:

```env
DB_CONNECTION=mysql
# Add your DB_DATABASE, DB_USERNAME, DB_PASSWORD...

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
REDIS_CLIENT=predis
```

### 3. Seed the Database

This command will migrate the tables, create the database triggers, and generate a test user with a System Master wallet.

```bash
php artisan migrate:fresh --seed
```

### 4. Run the Engine

To simulate the full production environment, open two terminal tabs:

```bash
# Tab 1: Start the API server
php artisan serve

# Tab 2: Start the asynchronous queue worker (Handles webhooks & SWR cache)
php artisan queue:work
```

---

## Testing the system

**1. The `api.http` File (VS Code REST Client)**
Open the `api.http` file in the root of the project. If you have the "REST Client" extension installed in VS Code, you can click "Send Request" to walk through the entire flow (Login -> 2FA Challenge -> Swap -> Ledger -> Webhook) without needing to manually copy/paste tokens.

**Important: The 2FA Challenge Step**
To successfully execute the `2fa/challenge` request in `api.http`, you must supply a valid 6-digit TOTP code:

1. When you run `php artisan migrate:fresh --seed`, the console will print a **TOTP Secret** for the test user.
2. Enter this secret into your **Google Authenticator** app, or use an online generator like [totp.app](https://totp.app/).
3. Copy the live 6-digit code.
4. Paste it into the `api.http` file under the 2FA Challenge request by replacing the `"totp_code"` value, and immediately hit "Send Request".

**2. The Parallel Concurrency Stress Test**
I wrote a specialized integration test that fires 10 simultaneous POST requests to the Swap API to prove that our Redis LUA script and Pessimistic Locks completely prevent race conditions. To watch it in action, make sure `php artisan serve` is running, then run:

```bash
php artisan test --filter SwapConcurrencyTest
```

**3. Static Analysis (PHPStan & Larastan)**
To prove the rigorous strict typing and architectural compliance of the codebase, I have integrated **Larastan** (PHPStan for Laravel) configured to an extremely strict **Level 8**. You can verify the static analysis by running:

```bash
./vendor/bin/phpstan analyse --memory-limit=2G
```

---

> **Disclosure:** I used AI tools to assist in researching standard integrations for TOTP authentication, exploring the Redis LUA script trick for mitigating distributed race conditions, and formatting some of the inline comments. This readme is also partly ai generated, expanded from my original draft.
