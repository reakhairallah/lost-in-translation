const API_BASE = "/lost-in-translation/lost-in-translation-server";

const screens = {
	loading: document.getElementById("loading-screen"),
	round: document.getElementById("round-screen"),
	reveal: document.getElementById("reveal-screen"),
};

let pollHandle = null;

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
	document.getElementById("feedback").textContent = "";
	document.getElementById("feedback").className = "feedback";
	document.getElementById("hint-display").textContent = "";
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
	updateHintUI(data.hints_remaining);
	updateGuessUI(data.already_guessed);

	if (data.status === "revealed") {
		stopPolling();
		playReveal(data);
		return;
	}

	document.getElementById("mangled-sentence").textContent = data.mangled_text;
	document.getElementById("timer").textContent = data.seconds_remaining;

	showScreen("round");
	startPolling();
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
	// Server is the single source of truth for time remaining and round status —
	// this just re-displays what round_status.php returns, never counts down locally.
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

	const feedback = document.getElementById("feedback");
	feedback.textContent = "Checking...";
	feedback.className = "feedback";

	const res = await apiSubmitGuess(guess);

	if (!res.success) {
		feedback.className = "feedback wrong";
		feedback.textContent = res.error;
		return;
	}

	const { result, points_awarded } = res.data;
	feedback.className = "feedback " + result;

	if (result === "correct") {
		feedback.textContent = `Correct! +${points_awarded} points`;
	} else if (result === "close") {
		feedback.textContent = "Close! (one guess per round — hang tight for reveal)";
	} else {
		feedback.textContent = "Not quite. (one guess per round — hang tight for reveal)";
	}

	// Only one guess per player per round — don't let them try again. The round
	// itself doesn't end early just because this player guessed (it's shared with
	// everyone else still playing), so keep polling; the reveal happens naturally
	// once the shared timer actually runs out.
	updateGuessUI(true);
});

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
	updateHintUI(hints_remaining);
});

const reveal = document.getElementById("play-again-btn");

reveal.addEventListener("click", async () => {
	const res = await apiNextRound();

	if (!res.success) {
		document.getElementById("feedback").textContent = res.error;
		return;
	}

	startRound();
});

function playReveal(data) {
	showScreen("reveal");

	document.getElementById("reveal-original").textContent =
		`Original: "${data.seed_text}"`;

	const container = document.getElementById("reveal-steps");
	container.innerHTML = "";
	document.getElementById("mangle-score-display").textContent = "";

	// char_delta isn't sent by the backend — derive it here from each step's length
	// vs. the previous one (or the seed, for the first hop).
	const stepEls = data.chain_steps.map((step, i) => {
		const prevLength =
			i === 0 ? data.seed_text.length : data.chain_steps[i - 1].text.length;
		const charDelta = step.text.length - prevLength;
		const deltaSign = charDelta > 0 ? "+" : "";

		const el = document.createElement("div");
		el.className = "reveal-step";
		el.innerHTML = `
      <span class="lang">${step.language_code}</span>
      <span class="delta">${deltaSign}${charDelta} chars</span>
      <div>${step.text}</div>
    `;
		container.appendChild(el);
		return el;
	});

	revealStepsSequentially(stepEls, data.mangle_score);
}

async function revealStepsSequentially(stepEls, mangleScore) {
	for (let i = 0; i < stepEls.length; i++) {
		await new Promise((resolve) => setTimeout(resolve, 1000)); // ~1 sec per hop
		stepEls[i].classList.add("visible");
	}

	await new Promise((resolve) => setTimeout(resolve, 400));
	document.getElementById("mangle-score-display").textContent =
		`Mangle Score: ${mangleScore} / 100`;
}
