<?php

// Some environments set serialize_precision to a large fixed value, which makes
// json_encode() print the full imprecise binary expansion of floats (e.g.
// 0.1999999999999999...) instead of a clean decimal. -1 uses the shortest
// round-trippable representation instead. Scoped to this request only.
ini_set("serialize_precision", -1);

include("bootstrap.php");
include("scoring.php");

$input = json_decode(file_get_contents("php://input"), true);
$guess_text = isset($input["guess"]) ? trim($input["guess"]) : "";

if ($guess_text === "") {
    echo json_encode(["success" => false, "error" => "guess cannot be empty"]);
    exit;
}

$sql = "SELECT current_round_id FROM game_state WHERE id = 1";
$query = $mysql->prepare($sql);
$query->execute();
$round_id = $query->get_result()->fetch_assoc()["current_round_id"];

if ($round_id == null) {
    echo json_encode(["success" => false, "error" => "no active round"]);
    exit;
}

$sql = "SELECT r.status, r.seed_id, s.text AS seed_text, TIMESTAMPDIFF(SECOND, r.opens_at, NOW()) AS seconds_elapsed
        FROM rounds r JOIN seeds s ON s.id = r.seed_id WHERE r.id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("i", $round_id);
$query->execute();
$round = $query->get_result()->fetch_assoc();

if ($round["status"] !== "open") {
    echo json_encode(["success" => false, "error" => "round is not open"]);
    exit;
}

$session_id = getSessionId();
$seed_text = $round["seed_text"];
$seconds_elapsed = (int) $round["seconds_elapsed"];

$sql = "SELECT COUNT(*) AS total FROM hints_used WHERE round_id = ? AND session_id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("is", $round_id, $session_id);
$query->execute();
$hints_used = (int) $query->get_result()->fetch_assoc()["total"];

$sim = similarity($guess_text, $seed_text);

if ($sim >= 0.85) {
    $result = "correct";
} else if ($sim >= 0.60) {
    $result = "close";
} else {
    $result = "wrong";
}

if ($result === "correct") {
    $points_awarded = max(0, 100 - (int) floor($seconds_elapsed / 5) - 30 * $hints_used);
} else {
    $points_awarded = 0;
}

try {
    $sql = "INSERT INTO guesses(round_id, session_id, guess_text, similarity, result, points_awarded) VALUES(?, ?, ?, ?, ?, ?)";
    $query = $mysql->prepare($sql);
    $query->bind_param("issdsi", $round_id, $session_id, $guess_text, $sim, $result, $points_awarded);
    $query->execute();
} catch (\mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        echo json_encode(["success" => false, "error" => "already guessed this round"]);
        exit;
    }
    throw $e;
}

$response = [];
$response["success"] = true;
$response["data"] = [];
$response["data"]["result"] = $result;
$response["data"]["similarity"] = round($sim, 4);
$response["data"]["points_awarded"] = $points_awarded;

echo json_encode($response);

?>
