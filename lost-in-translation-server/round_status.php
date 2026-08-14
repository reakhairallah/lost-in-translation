<?php

// See submit_guess.php — this environment's serialize_precision default makes floats
// like mangle_score print with a long imprecise binary expansion otherwise.
ini_set("serialize_precision", -1);

include("bootstrap.php");
include("round_logic.php");

$sql = "SELECT current_round_id FROM game_state WHERE id = 1";
$query = $mysql->prepare($sql);
$query->execute();
$state = $query->get_result()->fetch_assoc();
$round_id = $state["current_round_id"];

if ($round_id == null) {
    // Cold start — no round has ever run. Expected old value is NULL, since that's
    // what we just read game_state to be.
    $round_id = createRound();
    $won = openRound($round_id, null);

    if (!$won) {
        // A different simultaneous cold-start request beat us to it — use the round
        // that actually became active instead of our own orphaned one.
        $sql = "SELECT current_round_id FROM game_state WHERE id = 1";
        $query = $mysql->prepare($sql);
        $query->execute();
        $round_id = $query->get_result()->fetch_assoc()["current_round_id"];
    }
}

$sql = "SELECT id, status, TIMESTAMPDIFF(SECOND, NOW(), closes_at) AS seconds_remaining FROM rounds WHERE id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("i", $round_id);
$query->execute();
$round = $query->get_result()->fetch_assoc();

if ($round["status"] === "open" && $round["seconds_remaining"] <= 0) {
    closeRound($round_id);
    revealRound($round_id);

    // Re-fetch so the response reflects the new status.
    $sql = "SELECT id, status, TIMESTAMPDIFF(SECOND, NOW(), closes_at) AS seconds_remaining FROM rounds WHERE id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $round_id);
    $query->execute();
    $round = $query->get_result()->fetch_assoc();
}

$session_id = getSessionId();

$sql = "SELECT COUNT(*) AS total FROM guesses WHERE round_id = ? AND session_id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("is", $round_id, $session_id);
$query->execute();
$already_guessed = $query->get_result()->fetch_assoc()["total"] > 0;

$sql = "SELECT COUNT(*) AS total FROM hints_used WHERE round_id = ? AND session_id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("is", $round_id, $session_id);
$query->execute();
$hints_used_by_me = (int) $query->get_result()->fetch_assoc()["total"];

// The mangled sentence (final chain_steps hop, i.e. the highest hop_index for this
// round) is the puzzle itself, not the answer — safe to show at every status, unlike
// seed_text/chain_steps below which stay hidden until revealed. Falls back to null for
// an aborted round, which never got any chain_steps written.
$sql = "SELECT text FROM chain_steps WHERE round_id = ? ORDER BY hop_index DESC LIMIT 1";
$query = $mysql->prepare($sql);
$query->bind_param("i", $round_id);
$query->execute();
$final_step = $query->get_result()->fetch_assoc();
$mangled_text = $final_step != null ? $final_step["text"] : null;

$status = $round["status"];
$seconds_remaining = ($status === "closed" || $status === "revealed") ? 0 : max(0, (int) $round["seconds_remaining"]);

$response = [];
$response["success"] = true;
$response["data"] = [];
$response["data"]["round_id"] = (int) $round_id;
$response["data"]["status"] = $status;
$response["data"]["seconds_remaining"] = $seconds_remaining;
$response["data"]["hints_used_by_me"] = $hints_used_by_me;
$response["data"]["hints_remaining"] = 3 - $hints_used_by_me;
$response["data"]["already_guessed"] = $already_guessed;
$response["data"]["mangled_text"] = $mangled_text;

if ($status === "revealed") {
    $sql = "SELECT r.seed_id, s.text AS seed_text FROM rounds r JOIN seeds s ON s.id = r.seed_id WHERE r.id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $round_id);
    $query->execute();
    $seed_text = $query->get_result()->fetch_assoc()["seed_text"];

    $sql = "SELECT hop_index, language_code, text FROM chain_steps WHERE round_id = ? ORDER BY hop_index";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $round_id);
    $query->execute();
    $steps = $query->get_result()->fetch_all(MYSQLI_ASSOC);

    $sql = "SELECT mangle_score FROM hall_of_fame WHERE round_id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $round_id);
    $query->execute();
    $mangle_score = (float) $query->get_result()->fetch_assoc()["mangle_score"];

    $response["data"]["seed_text"] = $seed_text;
    $response["data"]["chain_steps"] = $steps;
    $response["data"]["mangle_score"] = $mangle_score;
}

echo json_encode($response);

?>
