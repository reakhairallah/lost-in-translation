const API_BASE = "/lost-in-translation/lost-in-translation-server";

// hall_of_fame.php only accepts "mangle" | "votes" (default "mangle") — matches the
// sort-btn data-sort attributes in hall-of-fame.html.
let currentSort = "mangle";

const sortButton = document.querySelectorAll(".sort-btn");

sortButton.forEach((btn) => {
	btn.addEventListener("click", () => {
		sortButton.forEach((b) => b.classList.remove("active"));
		btn.classList.add("active");
		currentSort = btn.dataset.sort;
		loadHallOfFame();
	});
});

async function apiGetHallOfFame(sort) {
	const res = await axios.get(`${API_BASE}/hall_of_fame.php`, {
		params: { sort },
		withCredentials: true,
	});
	return res.data;
}

async function apiVote(hofId) {
	const res = await axios.post(
		`${API_BASE}/vote.php`,
		{ hof_id: hofId },
		{ withCredentials: true }
	);
	return res.data;
}

async function loadHallOfFame() {
	const list = document.getElementById("hof-list");
	list.innerHTML = "<li>Loading...</li>";

	const res = await apiGetHallOfFame(currentSort);

	if (!res.success) {
		list.innerHTML = `<li>${res.error}</li>`;
		return;
	}

	list.innerHTML = "";

	res.data.entries.forEach((entry) => {
		const li = document.createElement("li");
		li.className = "hof-item";
		li.innerHTML = `
      <div class="sentence">
        <div>"${entry.seed_text}"</div>
        <div class="entry" > -> "${entry.final_text}"</div>
      </div>
      <div class="score">${entry.mangle_score}/100</div>
      <button class="vote-btn" data-id="${entry.id}">&#8593; ${entry.vote_count}</button>
    `;
		list.appendChild(li);
	});

	document.querySelectorAll(".vote-btn").forEach((btn) => {
		btn.addEventListener("click", async () => {
			if (btn.classList.contains("voted")) return; // one vote per player, enforced server-side too

			const id = btn.dataset.id;
			const res = await apiVote(id);

			if (res.success) {
				btn.innerHTML = `&#8593; ${res.data.vote_count}`;
				btn.classList.add("voted");
			} else {
				// Most likely "already voted" (e.g. from a previous visit) — treat the
				// same as a successful vote from the UI's perspective: lock the button.
				btn.classList.add("voted");
			}
		});
	});
}

loadHallOfFame();
