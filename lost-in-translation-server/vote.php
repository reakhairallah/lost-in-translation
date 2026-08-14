<?php

include("bootstrap.php");

$input = json_decode(file_get_contents("php://input"), true);
$hof_id = isset($input["hof_id"]) ? (int) $input["hof_id"] : null;

if ($hof_id == null) {
    echo json_encode(["success" => false, "error" => "hof_id is required"]);
    exit;
}

$sql = "SELECT id FROM hall_of_fame WHERE id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("i", $hof_id);
$query->execute();
$hof_row = $query->get_result()->fetch_assoc();

if ($hof_row == null) {
    echo json_encode(["success" => false, "error" => "no such hall of fame entry"]);
    exit;
}

$session_id = getSessionId();

try {
    $sql = "INSERT INTO votes(hof_id, voter_session_id) VALUES(?, ?)";
    $query = $mysql->prepare($sql);
    $query->bind_param("is", $hof_id, $session_id);
    $query->execute();
} catch (\mysqli_sql_exception $e) {
    if ($e->getCode() === 1062) {
        echo json_encode(["success" => false, "error" => "already voted"]);
        exit;
    }
    throw $e;
}

$sql = "SELECT COUNT(*) AS total FROM votes WHERE hof_id = ?";
$query = $mysql->prepare($sql);
$query->bind_param("i", $hof_id);
$query->execute();
$vote_count = (int) $query->get_result()->fetch_assoc()["total"];

$response = [];
$response["success"] = true;
$response["data"] = [];
$response["data"]["vote_count"] = $vote_count;

echo json_encode($response);

?>
