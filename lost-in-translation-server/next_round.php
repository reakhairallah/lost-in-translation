<?php

include("bootstrap.php");
include("round_logic.php");

$sql = "SELECT current_round_id FROM game_state WHERE id = 1";
$query = $mysql->prepare($sql);
$query->execute();
$current_round_id = $query->get_result()->fetch_assoc()["current_round_id"];

if ($current_round_id == null) {
    echo json_encode(["success" => false, "error" => "no active round to advance from"]);
    exit;
}

$sql = "SELECT status FROM rounds WHERE id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("i", $current_round_id);
$query->execute();
$current_round = $query->get_result()->fetch_assoc();

if ($current_round["status"] !== "revealed") {
    echo json_encode(["success" => false, "error" => "current round is not revealed yet"]);
    exit;
}

$session_id = getSessionId();

// Chain failure handling (decision 4): createRound() may hand back an aborted round
// if the translation chain failed. Auto-retry with a new seed rather than surfacing
// a dead round to the player.
$new_round_id = null;
for ($attempt = 0; $attempt < 3; $attempt++) {
    $candidate_id = createRound();

    $sql = "SELECT status FROM rounds WHERE id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $candidate_id);
    $query->execute();
    $candidate_status = $query->get_result()->fetch_assoc()["status"];

    if ($candidate_status !== "aborted") {
        $new_round_id = $candidate_id;
        break;
    }
}

if ($new_round_id == null) {
    echo json_encode(["success" => false, "error" => "failed to generate a new round, please try again"]);
    exit;
}

$sql = "UPDATE rounds SET triggered_by_session_id = ? WHERE id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("si", $session_id, $new_round_id);
$query->execute();

// Pass the round id we originally checked was 'revealed' as the expected old value —
// NOT a freshly re-read one. Otherwise a slow request (createRound() makes real
// translation API calls, taking seconds) could read game_state only after a faster
// concurrent request already advanced it, and "validly" clobber that newer round out
// from under a player who's already on it.
$won = openRound($new_round_id, $current_round_id);

if (!$won) {
    // We lost the race — discard our round rather than leave a dangling 'open' row
    // that game_state doesn't point to.
    $sql = "UPDATE rounds SET status = 'aborted', abort_reason = 'superseded by a concurrent next-round request' WHERE id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $new_round_id);
    $query->execute();

    $sql = "SELECT current_round_id FROM game_state WHERE id = 1";
    $query = $mysql->prepare($sql);
    $query->execute();
    $result_round_id = $query->get_result()->fetch_assoc()["current_round_id"];
} else {
    $result_round_id = $new_round_id;
}

$sql = "SELECT status FROM rounds WHERE id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("i", $result_round_id);
$query->execute();
$result_status = $query->get_result()->fetch_assoc()["status"];

$response = [];
$response["success"] = true;
$response["data"] = [];
$response["data"]["round_id"] = (int) $result_round_id;
$response["data"]["status"] = $result_status;

echo json_encode($response);

?>
