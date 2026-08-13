let currentSort = "mangle_score";

const sortButton = document.querySelectorAll(".sort-btn");

sortButton.forEach((btn) => {
	btn.addEventListener("click", () => {
		sortButton.forEach((b) => b.classList.remove("active"));
		btn.classList.add("active");
		currentSort = btn.dataset.sort;
		loadHallOfFame();
	});
});

async function loadHallOfFame() {
	const list = document.getElementById("hof-list");
	list.innerHTML = "<li>Loading...</li>";

	const data = await apiGetHallOfFame(currentSort); // <-- add your api anem for the hall of fame
	list.innerHTML = "";

	data.entries.forEach((entry) => {
		const li = document.createElement("li");
		li.className = "hof-item";
		li.innerHTML = `
      <div class="sentence">
        <div>"${entry.original}"</div>
        <div class="entry" > -> "${entry.final}"</div>
      </div>
      <div class="score">${entry.mangle_score}/100</div>
      <button class="vote-btn" data-id="${entry.id}"> ^ ${entry.votes}</button>
    `;
		list.appendChild(li);
	});

	document.querySelectorAll(".vote-btn").forEach((btn) => {
		btn.addEventListener("click", async () => {
			if (btn.classList.contains("voted")) return; // one vote per player, enforced server-side too
			const id = btn.dataset.id;
			const res = await apiVote(id); // <-- add your api name for votes
			if (res.success) {
				btn.textContent = `^ ${res.new_vote_count}`;
				btn.classList.add("voted");
			}
		});
	});
}

loadHallOfFame();
