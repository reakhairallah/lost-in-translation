const API_BASE = "/lost-in-translation/lost-in-translation-server";
const ROUND_DURATION = 60;

const screens = {
	loading: document.getElementById("loading-screen"),
	round: document.getElementById("round-screen"),
	reveal: document.getElementById("reveal-screen"),
};

const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

let pollHandle = null;
let tickHandle = null;
let displayedRemaining = 0;

function showScreen(name) {
	Object.values(screens).forEach((s) => s.classList.remove("active"));
	screens[name].classList.add("active");
}

// --- API calls, matching docs/endpoints.md exactly ---

async function apiRoundState() {
	const res = await axios.get(`${API_BASE}/round_status.php`, {
		withCredentials: true,
	});
	return res.data;
}

async function apiNextRound() {
	const res = await axios.post(
		`${API_BASE}/next_round.php`,
		{},
		{ withCredentials: true }
	);
	return res.data;
}

async function apiSubmitGuess(guess) {
	const res = await axios.post(
		`${API_BASE}/submit_guess.php`,
		{ guess },
		{ withCredentials: true }
	);
	return res.data;
}

async function apiUseHint() {
	const res = await axios.post(
		`${API_BASE}/use_hint.php`,
		{},
		{ withCredentials: true }
	);
	return res.data;
}

startRound();

async function startRound() {
	showScreen("loading");
	document.getElementById("board").innerHTML = "";
	const hintDisplay = document.getElementById("hint-display");
	hintDisplay.textContent = "";
	hintDisplay.classList.remove("visible");
	document.getElementById("guess-input").value = "";

	// round_status.php bootstraps the very first round itself on cold start — nothing
	// else needs to be called before this to "start" a round.
	const state = await apiRoundState();

	if (!state.success) {
		document.getElementById("mangled-sentence").textContent =
			"Something went wrong. Please refresh.";
		return;
	}

	applyRoundState(state.data);
}

function applyRoundState(data) {
	document.getElementById("round-id-display").textContent = `Round #${data.round_id}`;
	updateHintUI(data.hints_remaining);
	updateGuessUI(data.already_guessed);

	if (data.status === "revealed") {
		stopPolling();
		stopLocalTick();
		playReveal(data);
		return;
	}

	document.getElementById("mangled-sentence").textContent = data.mangled_text;
	displayedRemaining = data.seconds_remaining;
	updateWheel(displayedRemaining);
	startLocalTick();

	showScreen("round");
	startPolling();
}

function updateWheel(secondsRemaining) {
	const wheel = document.getElementById("wheel");
	const pct = (Math.max(secondsRemaining, 0) / ROUND_DURATION) * 100;
	wheel.style.setProperty("--pct", pct.toFixed(1));
	wheel.classList.toggle("is-low", secondsRemaining <= 5 && secondsRemaining > 0);
	document.getElementById("timer").textContent = Math.max(secondsRemaining, 0);
}

function startLocalTick() {
	stopLocalTick();
	// Purely visual, ticks every 1s so the wheel counts down smoothly between polls.
	// The 2s poll in startPolling() remains the authoritative resync — this can never
	// drift more than one second off before the next poll corrects it.
	tickHandle = setInterval(() => {
		displayedRemaining = Math.max(displayedRemaining - 1, 0);
		updateWheel(displayedRemaining);
	}, 1000);
}

function stopLocalTick() {
	if (tickHandle) {
		clearInterval(tickHandle);
		tickHandle = null;
	}
}

function updateHintUI(hintsRemaining) {
	const btn = document.getElementById("hint-btn");
	btn.disabled = hintsRemaining <= 0;
	btn.textContent =
		hintsRemaining > 0
			? `Use Hint (-30 pts) [${hintsRemaining} left]`
			: "No hints left";
}

function updateGuessUI(alreadyGuessed) {
	document.getElementById("guess-input").disabled = alreadyGuessed;
	document.querySelector('#guess-form button[type="submit"]').disabled =
		alreadyGuessed;
}

function startPolling() {
	stopPolling();
	// Server is the single source of truth for time remaining and round status.
	// startLocalTick() ticks the displayed number down for smoothness between polls,
	// but every 2s this resyncs displayedRemaining straight from the server, so it
	// can never drift for more than a second.
	pollHandle = setInterval(async () => {
		const state = await apiRoundState();
		if (!state.success) return;
		applyRoundState(state.data);
	}, 2000);
}

function stopPolling() {
	if (pollHandle) {
		clearInterval(pollHandle);
		pollHandle = null;
	}
}

const submission = document.getElementById("guess-form");

submission.addEventListener("submit", async (e) => {
	e.preventDefault();
	const input = document.getElementById("guess-input");
	const guess = input.value.trim();
	if (!guess) return;

	const res = await apiSubmitGuess(guess);
	const board = document.getElementById("board");

	if (!res.success) {
		addBoardEntry(board, "wrong", res.error);
		return;
	}

	const { result, points_awarded } = res.data;
	const messages = {
		correct: `Correct! +${points_awarded} points`,
		close: "Close! (one guess per round — hang tight for reveal)",
		wrong: "Not quite. (one guess per round — hang tight for reveal)",
	};
	addBoardEntry(board, result, messages[result]);

	// Only one guess per player per round — don't let them try again. The round
	// itself doesn't end early just because this player guessed (it's shared with
	// everyone else still playing), so keep polling; the reveal happens naturally
	// once the shared timer actually runs out.
	updateGuessUI(true);
});

function addBoardEntry(board, verdict, text) {
	const chipLabel = { correct: "Correct", close: "Close", wrong: "Wrong" }[verdict];
	const row = document.createElement("div");
	row.className = "board-entry";
	row.innerHTML = `
		<span class="chip ${verdict}">${chipLabel}</span>
		<span class="text"></span>
	`;
	row.querySelector(".text").textContent = text;
	board.prepend(row);
}

const hints = document.getElementById("hint-btn");

hints.addEventListener("click", async () => {
	const res = await apiUseHint();
	const display = document.getElementById("hint-display");

	if (!res.success) {
		display.textContent = res.error;
		return;
	}

	const { language_code, text, hints_remaining } = res.data;
	display.textContent = `Hint (${language_code}): "${text}"`;
	display.classList.add("visible");
	updateHintUI(hints_remaining);
});

const reveal = document.getElementById("play-again-btn");

reveal.addEventListener("click", async () => {
	const errorEl = document.getElementById("reveal-error");
	errorEl.textContent = "";

	const res = await apiNextRound();

	if (!res.success) {
		errorEl.textContent = res.error;
		return;
	}

	startRound();
});

function playReveal(data) {
	showScreen("reveal");

	document.getElementById("reveal-error").textContent = "";
	document.getElementById("round-id-display").textContent = `Round #${data.round_id}`;
	document.getElementById("reveal-original-text").textContent = `"${data.seed_text}"`;

	const container = document.getElementById("reveal-steps");
	container.innerHTML = "";

	const scoreDisplay = document.getElementById("mangle-score-display");
	scoreDisplay.classList.remove("visible");

	const total = data.chain_steps.length;
	const stepEls = data.chain_steps.map((step, i) => {
		const el = document.createElement("div");
		el.className = "reveal-step" + (i === total - 1 ? " final" : "");
		el.innerHTML = `
			<div class="badge">${step.language_code}</div>
			<div class="step-body">
				<div class="step-meta">Translation ${i + 1} of ${total}</div>
				<div class="step-text"></div>
			</div>
		`;
		el.querySelector(".step-text").textContent = step.text;
		container.appendChild(el);
		return el;
	});

	revealStepsSequentially(stepEls, data.mangle_score);
}

async function revealStepsSequentially(stepEls, mangleScore) {
	const scoreDisplay = document.getElementById("mangle-score-display");
	document.getElementById("mangle-num").textContent = Math.round(mangleScore);

	if (reduceMotion) {
		stepEls.forEach((el) => el.classList.add("visible"));
		scoreDisplay.classList.add("visible");
		return;
	}

	for (let i = 0; i < stepEls.length; i++) {
		await new Promise((resolve) => setTimeout(resolve, 1000)); // ~1 sec per hop
		stepEls[i].classList.add("visible");
	}

	await new Promise((resolve) => setTimeout(resolve, 400));
	scoreDisplay.classList.add("visible");
}
