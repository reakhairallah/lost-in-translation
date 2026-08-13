/* ==========================================================================
   MOCK API — simulates the PHP endpoints defined in the API contract.
   Student C: build against this. Once Students A & B have real PHP endpoints,
   replace each function body with a real fetch() call to /api/*.php using
   the SAME function names and SAME return shape, and nothing else changes.
   ========================================================================== */

const MOCK_DELAY = 600; // ms, simulate network/API latency

const MOCK_CHAIN_STEPS = [
  { lang: "en", label: "English (original)", text: "It's raining cats and dogs." },
  { lang: "ja", label: "Japanese",  text: "猫と犬が降っています。" },
  { lang: "ar", label: "Arabic",    text: "القطط والكلاب تتساقط." },
  { lang: "fi", label: "Finnish",   text: "Kissoja ja koiria putoaa." },
  { lang: "sw", label: "Swahili",   text: "Paka na mbwa wanaanguka." },
  { lang: "hu", label: "Hungarian", text: "Macskák és kutyák esnek." },
  { lang: "ko", label: "Korean",    text: "고양이와 개가 떨어지고 있습니다." },
  { lang: "en", label: "English (final)", text: "Cats and dogs are falling." }
];

// running mock state for the "current round"
let _mockRound = {
  round_id: null,
  timeRemaining: 30,
  timerHandle: null,
  seed: MOCK_CHAIN_STEPS[0].text,
  final: MOCK_CHAIN_STEPS[MOCK_CHAIN_STEPS.length - 1].text,
  hintsUsed: 0,
  revealedHints: []
};

function _delay(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

/* POST /generate_round.php
   returns { round_id, status } */
async function apiGenerateRound() {
  await _delay(MOCK_DELAY * 2); // pretend 7 sequential API calls happened
  _mockRound.round_id = "mock-" + Date.now();
  _mockRound.timeRemaining = 30;
  _mockRound.hintsUsed = 0;
  _mockRound.revealedHints = [];
  return { round_id: _mockRound.round_id, status: "open" };
}

/* GET /round_state.php?round_id=X
   returns { status, time_remaining, mangled_sentence } */
async function apiRoundState() {
  await _delay(150);
  _mockRound.timeRemaining = Math.max(0, _mockRound.timeRemaining);
  return {
    status: _mockRound.timeRemaining > 0 ? "open" : "closed",
    time_remaining: _mockRound.timeRemaining,
    mangled_sentence: _mockRound.final
  };
}

/* call this locally on your own setInterval to simulate the server clock ticking */
function apiTickTimer() {
  if (_mockRound.timeRemaining > 0) _mockRound.timeRemaining--;
}

/* POST /submit_guess.php  body: { guess_text }
   returns { result: "correct"|"close"|"wrong", score } */
async function apiSubmitGuess(guessText) {
  await _delay(300);
  const similarity = _mockSimilarity(guessText, _mockRound.seed);
  let result, score = 0;
  if (similarity >= 0.85) {
    result = "correct";
    score = Math.max(0, 100 - (30 - _mockRound.timeRemaining) - (_mockRound.hintsUsed * 30));
  } else if (similarity >= 0.60) {
    result = "close";
  } else {
    result = "wrong";
  }
  return { result, score, similarity: Math.round(similarity * 100) / 100 };
}

/* POST /hint.php
   returns { hop_number, language, text, points_deducted } */
async function apiUseHint() {
  await _delay(200);
  const nextHop = _mockRound.hintsUsed + 1; // 1-indexed into intermediate steps
  if (nextHop >= MOCK_CHAIN_STEPS.length - 1) {
    return { error: "No more hints available" };
  }
  _mockRound.hintsUsed++;
  const step = MOCK_CHAIN_STEPS[nextHop];
  _mockRound.revealedHints.push(step);
  return {
    hop_number: nextHop,
    language: step.label,
    text: step.text,
    points_deducted: 30
  };
}

/* GET /round_state.php?reveal=1
   returns { original, steps: [{lang, label, text, char_delta}], mangle_score } */
async function apiGetReveal() {
  await _delay(300);
  const steps = MOCK_CHAIN_STEPS.map((s, i) => {
    const prevLen = i === 0 ? s.text.length : MOCK_CHAIN_STEPS[i - 1].text.length;
    return {
      lang: s.lang,
      label: s.label,
      text: s.text,
      char_delta: s.text.length - prevLen
    };
  });
  const mangleScore = Math.round((1 - _mockSimilarity(_mockRound.seed, _mockRound.final)) * 100);
  return {
    original: _mockRound.seed,
    steps,
    mangle_score: mangleScore
  };
}

/* GET /hall_of_fame.php?sort=mangle_score|votes
   returns { entries: [{id, original, final, mangle_score, votes, voted}] } */
async function apiGetHallOfFame(sortBy) {
  await _delay(300);
  const entries = [
    { id: 1, original: "He let the cat out of the bag.", final: "The cat left the sack by itself.", mangle_score: 78, votes: 12, voted: false },
    { id: 2, original: "Break a leg out there tonight.", final: "Destroy your foot this evening.", mangle_score: 91, votes: 25, voted: false },
    { id: 3, original: "The early bird catches the worm.", final: "First bird eats worm early.", mangle_score: 42, votes: 5, voted: false },
    { id: 4, original: "It's raining cats and dogs.", final: "Cats and dogs are falling.", mangle_score: 55, votes: 8, voted: false }
  ];
  entries.sort((a, b) => b[sortBy] - a[sortBy]);
  return { entries };
}

/* POST /hall_of_fame.php  body: { id }
   returns { success, new_vote_count } */
async function apiVote(id) {
  await _delay(200);
  return { success: true, new_vote_count: Math.floor(Math.random() * 30) + 1 };
}

/* --- tiny local similarity helper just for mock feedback, NOT the real algorithm --- */
function _mockSimilarity(a, b) {
  a = a.toLowerCase().replace(/[^\w\s]/g, "").trim();
  b = b.toLowerCase().replace(/[^\w\s]/g, "").trim();
  const wordsA = new Set(a.split(/\s+/));
  const wordsB = new Set(b.split(/\s+/));
  const intersection = [...wordsA].filter(w => wordsB.has(w)).length;
  const union = new Set([...wordsA, ...wordsB]).size;
  return union === 0 ? 0 : intersection / union;
}
