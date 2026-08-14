<?php

include("bootstrap.php");

$sql = "SELECT current_round_id FROM game_state WHERE id = 1";
$query = $mysql->prepare($sql);
$query->execute();
$round_id = $query->get_result()->fetch_assoc()["current_round_id"];

if ($round_id == null) {
    echo json_encode(["success" => false, "error" => "no active round"]);
    exit;
}

$sql = "SELECT status FROM rounds WHERE id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("i", $round_id);
$query->execute();
$round = $query->get_result()->fetch_assoc();

if ($round["status"] !== "open") {
    echo json_encode(["success" => false, "error" => "round is not open"]);
    exit;
}

$session_id = getSessionId();

$sql = "SELECT COUNT(*) AS total FROM hints_used WHERE round_id = ? AND session_id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("is", $round_id, $session_id);
$query->execute();
$hints_used = (int) $query->get_result()->fetch_assoc()["total"];

if ($hints_used >= 3) {
    echo json_encode(["success" => false, "error" => "no hints remaining"]);
    exit;
}

$hint_number = $hints_used + 1;
$hop_index_revealed = $hint_number;

try {
    $sql = "INSERT INTO hints_used(round_id, session_id, hint_number, hop_index_revealed) VALUES(?, ?, ?, ?)";
    $query = $mysql->prepare($sql);
    $query->bind_param("isii", $round_id, $session_id, $hint_number, $hop_index_revealed);
    $query->execute();
} catch (\mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        echo json_encode(["success" => false, "error" => "hint already claimed, please retry"]);
        exit;
    }
    throw $e;
}

$sql = "SELECT language_code, text FROM chain_steps WHERE round_id = ? AND hop_index = ?";
$query = $mysql->prepare($sql);
$query->bind_param("ii", $round_id, $hop_index_revealed);
$query->execute();
$step = $query->get_result()->fetch_assoc();

$response = [];
$response["success"] = true;
$response["data"] = [];
$response["data"]["hop_index_revealed"] = $hop_index_revealed;
$response["data"]["language_code"] = $step["language_code"];
$response["data"]["text"] = $step["text"];
$response["data"]["hints_remaining"] = 3 - $hint_number;

echo json_encode($response);

?>
