# Endpoints (Student B → Student C contract)

Keep this file in sync with every endpoint as it's built. Response shape is always
`{"success": true/false, "data": {...}}` on success or `{"success": false, "error": "..."}`
on failure (see CLAUDE.md).

## GET /round_status.php

Polled by the frontend to get the current round's state. Also responsible for
bootstrapping the very first round (cold start) and for auto-transitioning an expired
round from `open` → `closed` → `revealed` on whoever polls next — there's no separate
timer process.

Identifies the caller via the `session_id` cookie (set automatically by
`getSessionId()` if missing).

**Request:** no body/params.

**Response `data`:**

| field | type | notes |
|---|---|---|
| `round_id` | int | |
| `status` | string | `generated` \| `open` \| `closed` \| `revealed` \| `aborted` |
| `seconds_remaining` | int | `0` once `closed`/`revealed` |
| `hints_used_by_me` | int | for this session, this round |
| `hints_remaining` | int | `3 - hints_used_by_me` |
| `already_guessed` | bool | has this session submitted a guess this round |
| `mangled_text` | string \| null | the puzzle itself — final chain hop's text (highest `hop_index`, always the `en` result). Present at **every** status, since it's what players guess from, not the answer. `null` only for an `aborted` round (never got any `chain_steps` written). |
| `seed_text` | string | **only present when `status === "revealed"`** |
| `chain_steps` | array | **only present when `status === "revealed"`** — each item: `hop_index`, `language_code`, `text` |
| `mangle_score` | float | **only present when `status === "revealed"`** — 0–100, higher = more destroyed (same value stored in `hall_of_fame`) |

`seed_text` and `chain_steps` are deliberately omitted at every other status — never sent
before reveal, since that would leak the answer. `mangled_text` is different: it's the
mangled output itself, which is the whole puzzle players see and guess from, so it's
safe (and necessary) to send from the moment the round opens.

Example (`open`):
```json
{"success": true, "data": {
  "round_id": 6, "status": "open", "seconds_remaining": 42,
  "hints_used_by_me": 1, "hints_remaining": 2, "already_guessed": false,
  "mangled_text": "Could he eat the frog with that sound?"
}}
```

Example (`revealed`):
```json
{"success": true, "data": {
  "round_id": 6, "status": "revealed", "seconds_remaining": 0,
  "hints_used_by_me": 1, "hints_remaining": 2, "already_guessed": true,
  "mangled_text": "Could he eat the frog with that sound?",
  "seed_text": "Bite the bullet.",
  "mangle_score": 88.4615,
  "chain_steps": [
    {"hop_index": 1, "language_code": "ja", "text": "..."},
    ...
    {"hop_index": 7, "language_code": "en", "text": "..."}
  ]
}}
```

## POST /submit_guess.php

Submits this session's one guess for the current round. Enforced server-side as
one-guess-per-session-per-round (decision 5) via a DB unique constraint — a second
attempt is rejected with a clear error, not a raw DB failure.

Identifies the caller via the `session_id` cookie (set automatically by
`getSessionId()` if missing).

**Request body** (JSON):

```json
{"guess": "the player's guess text"}
```

**Response `data`** (on `success: true`):

| field | type | notes |
|---|---|---|
| `result` | string | `correct` \| `close` \| `wrong` — similarity thresholds: `>= 0.85` correct, `0.60–0.85` close, below wrong |
| `similarity` | float | 0.0–1.0, rounded to 4 decimal places |
| `points_awarded` | int | `0` unless `result === "correct"`; otherwise `max(0, 100 - seconds_elapsed_since_open - 30 * hints_used_this_round)` |

Seed text is **never** included in this response, on success or failure — a wrong/close
guess must not leak the answer.

**Failure cases** (`success: false`, with `error`):
- Empty/whitespace-only guess — rejected before any DB interaction.
- No active round (`game_state.current_round_id` is `NULL`).
- Round exists but `status !== 'open'` (closed/revealed since the player loaded the
  page — a real race, not just a hypothetical).
- Session already has a guess on this round (`"already guessed this round"`) — caught
  from the DB's duplicate-key error, not a pre-check-then-insert (avoids the race
  between check and write).

Example (success):
```json
{"success": true, "data": {"result": "close", "similarity": 0.7231, "points_awarded": 0}}
```

Example (failure):
```json
{"success": false, "error": "already guessed this round"}
```

## POST /use_hint.php

Claims this session's next hint for the current round. Hints are never player-chosen —
always the next hidden hop in sequence (hint 1 reveals hop 1, hint 2 reveals hop 2, hint
3 reveals hop 3). Capped at 3 per session per round (decision 7), enforced by the DB
(`chk_hint_cap` + `uniq_round_session_hint`), not just an application-level count check.

Identifies the caller via the `session_id` cookie (set automatically by
`getSessionId()` if missing).

**Request:** no body.

**Response `data`** (on `success: true`):

| field | type | notes |
|---|---|---|
| `hop_index_revealed` | int | which hop this hint revealed (1–3) |
| `language_code` | string | that hop's language |
| `text` | string | that hop's translated text |
| `hints_remaining` | int | `3 -` this session's new hint count on this round |

**Failure cases** (`success: false`, with `error`):
- No active round.
- Round exists but `status !== 'open'`.
- Session already has 3 hints on this round (`"no hints remaining"`) — checked before
  attempting the insert.
- Two near-simultaneous requests both slip past that count check (a real race) — the
  DB's unique constraint rejects the loser, caught as a duplicate-key error and turned
  into `"hint already claimed, please retry"` rather than a raw DB error, same pattern
  as `submit_guess.php`.

Example (success):
```json
{"success": true, "data": {"hop_index_revealed": 2, "language_code": "ar", "text": "...", "hints_remaining": 1}}
```

## POST /next_round.php

The "start next round" button's endpoint (decision 3 — next round is player-triggered,
not automatic). Rejects unless the current round has finished revealing.

**Request:** no body.

**Response `data`** (on `success: true`):

| field | type | notes |
|---|---|---|
| `round_id` | int | the round that's now active — see race note below |
| `status` | string | status of that round (`open` on the normal path) |

**Failure cases** (`success: false`, with `error`):
- No active round to advance from (`game_state.current_round_id` is `NULL`).
- Current round exists but `status !== 'revealed'` — can't start a new round while the
  current one is still `generated`/`open`/`closed`.
- Chain generation failed 3 times in a row (`"failed to generate a new round, please try
  again"`) — extremely unlikely; each attempt auto-retries with a new seed per decision
  4 before giving up.

**Concurrent clicks (decision 3):** if two players hit this at the same moment, only one
round actually becomes active — verified directly by firing two genuinely concurrent
requests against a real server. The loser does **not** get an error: its own
speculatively-created round is marked `aborted` (`abort_reason: "superseded by a
concurrent next-round request"`) and its response instead reports the round that
actually won. Both callers therefore end up agreeing on the same `round_id`.

Example (success):
```json
{"success": true, "data": {"round_id": 28, "status": "open"}}
```

Example (failure):
```json
{"success": false, "error": "current round is not revealed yet"}
```

## GET /hall_of_fame.php

Returns the top 20 most-destroyed rounds ever revealed (decision 9 — every revealed
round enters the Hall of Fame, no threshold gate at insert; decision 10 — display is
limited, storage is not).

**Request query params:**

| param | values | notes |
|---|---|---|
| `sort` | `mangle` \| `votes` | optional, defaults to `mangle`. Any other/missing value also falls back to `mangle`. |

**Response `data`:**

```json
{"entries": [ {...}, {...}, ... ]}
```

Each entry:

| field | type | notes |
|---|---|---|
| `id` | int | `hall_of_fame.id` |
| `seed_text` | string | the original English sentence |
| `final_text` | string | the final `en` hop's text (highest `hop_index` for that round) |
| `mangle_score` | float | 0–100, higher = more destroyed |
| `vote_count` | int | `0` if nobody's voted for it yet |

Sorted by `mangle_score DESC` (`sort=mangle`) or `vote_count DESC` (`sort=votes`),
limited to 20 rows.

Example:
```json
{"success": true, "data": {"entries": [
  {"id": 6, "seed_text": "Speak of the devil.", "final_text": "- If there's a rumor.", "mangle_score": 94.4444, "vote_count": 0},
  {"id": 1, "seed_text": "Bite the bullet.", "final_text": "- You two go everywhere together?", "mangle_score": 80, "vote_count": 2}
]}}
```

## POST /vote.php

Casts this session's vote for a Hall of Fame entry. One vote per session per entry
(DB unique constraint `uniq_vote (hof_id, voter_session_id)`), enforced by letting the
insert fail on conflict rather than a pre-check-then-insert.

Identifies the caller via the `session_id` cookie (set automatically by
`getSessionId()` if missing).

**Request body** (JSON):

```json
{"hof_id": 6}
```

`hof_id` is the field name — matches the actual `hall_of_fame`/`votes` column name
directly, no `guess`/`guess_text`-style split needed here.

**Response `data`** (on `success: true`):

| field | type | notes |
|---|---|---|
| `vote_count` | int | this entry's new total vote count, after this vote |

**Failure cases** (`success: false`, with `error`):
- `hof_id` missing from the request body.
- `hof_id` doesn't correspond to a real `hall_of_fame` row (`"no such hall of fame
  entry"`) — checked explicitly, not a silent no-op.
- Session already voted for this entry (`"already voted"`) — caught from the DB's
  duplicate-key error, same pattern as `submit_guess.php`/`use_hint.php`.

Example (success):
```json
{"success": true, "data": {"vote_count": 3}}
```

Example (failure):
```json
{"success": false, "error": "already voted"}
```

Example (failure):
```json
{"success": false, "error": "no hints remaining"}
```
