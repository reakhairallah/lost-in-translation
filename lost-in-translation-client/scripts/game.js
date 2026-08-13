const screens = {
	loading: document.getElementById("loading-screen"),
	round: document.getElementById("round-screen"),
	reveal: document.getElementById("reveal-screen"),
};

let pollHandle = null;
let localTickHandle = null;

function showScreen(name) {
	Object.values(screens).forEach((s) => s.classList.remove("active"));
	screens[name].classList.add("active");
}

startRound();

async function startRound() {
	showScreen("loading");
	document.getElementById("feedback").textContent = "";
	document.getElementById("feedback").className = "feedback";
	document.getElementById("hint-display").textContent = "";
	document.getElementById("guess-input").value = "";

	await apiGenerateRound(); // <-- change the name to your api for starting the round

	const state = await apiRoundState(); // <-- change the name to your api for teh state of teh round
	document.getElementById("mangled-sentence").textContent =
		state.mangled_sentence;

	showScreen("round");
	startPolling();
}

// --- add the axios for the timer ---

const submission = document.getElementById("guess-form");

submission.addEventListener("submit", async (e) => {
	e.preventDefault();
	const input = document.getElementById("guess-input");
	const guess = input.value.trim();
	if (!guess) return;

	const feedback = document.getElementById("feedback");
	feedback.textContent = "Checking...";
	feedback.className = "feedback";

	const res = await apiSubmitGuess(guess); // <-- change to the api name for submitting response

	feedback.className = "feedback " + res.result;
	if (res.result === "correct") {
		feedback.textContent = `Correct! +${res.score} points`;
		stopPolling();
		setTimeout(playReveal, 1200); //stop time here and start function playReveal
	} else if (res.result === "close") {
		feedback.textContent = "Close! Try again.";
	} else {
		feedback.textContent = "Not quite. Keep guessing!";
	}
});

const hints = document.getElementById("hint-btn");

hints.addEventListener("click", async () => {
	const res = await apiUseHint(); // <-- add your api name for hints
	const display = document.getElementById("hint-display");
	if (res.error) {
		display.textContent = res.error;
		return;
	}
	display.textContent = `Hint (${res.language}): "${res.text}" (-${res.points_deducted} pts)`;
});

const reveal = document.getElementById("play-again-btn");

reveal.addEventListener("click", startRound);

async function playReveal() {
	stopPolling(); // Stop Time or stop counting
	showScreen("reveal");

	const revealData = await apiGetReveal(); // <-- put your name of teh api to reveal teh answer

	document.getElementById("reveal-original").textContent =
		`Original: "${revealData.original}"`;

	const container = document.getElementById("reveal-steps");
	container.innerHTML = "";
	document.getElementById("mangle-score-display").textContent = "";

	// build all step elements up front, reveal one at a time
	const stepEls = revealData.steps.map((step) => {
		const el = document.createElement("div");
		el.className = "reveal-step";
		const deltaSign = step.char_delta > 0 ? "+" : "";
		el.innerHTML = `
      <span class="lang">${step.label}</span>
      <span class="delta">${deltaSign}${step.char_delta} chars</span>
      <div>${step.text}</div>
    `;
		container.appendChild(el);
		return el;
	});

	for (let i = 0; i < stepEls.length; i++) {
		await new Promise((resolve) => setTimeout(resolve, 1000)); // ~1 sec per hop
		stepEls[i].classList.add("visible");
	}

	await new Promise((resolve) => setTimeout(resolve, 400));
	document.getElementById("mangle-score-display").textContent =
		`Mangle Score: ${revealData.mangle_score} / 100`;
}
