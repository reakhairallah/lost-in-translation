<?php

// See submit_guess.php — this environment's serialize_precision default makes floats
// like mangle_score print with a long imprecise binary expansion otherwise.
ini_set("serialize_precision", -1);

include("bootstrap.php");

$sort = isset($_GET["sort"]) ? $_GET["sort"] : "mangle";

if ($sort === "votes") {
    $order_by = "vote_count DESC";
} else {
    $order_by = "hf.mangle_score DESC";
}

$sql = "SELECT hf.id, s.text AS seed_text,
            (SELECT cs.text FROM chain_steps cs WHERE cs.round_id = hf.round_id ORDER BY cs.hop_index DESC LIMIT 1) AS final_text,
            hf.mangle_score,
            COALESCE(v.vote_count, 0) AS vote_count
        FROM hall_of_fame hf
        JOIN seeds s ON s.id = hf.seed_id
        LEFT JOIN (SELECT hof_id, COUNT(*) AS vote_count FROM votes GROUP BY hof_id) v ON v.hof_id = hf.id
        ORDER BY $order_by
        LIMIT 20";
$query = $mysql->prepare($sql);
$query->execute();
$entries = $query->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($entries as &$entry) {
    $entry["id"] = (int) $entry["id"];
    $entry["mangle_score"] = (float) $entry["mangle_score"];
    $entry["vote_count"] = (int) $entry["vote_count"];
}

$response = [];
$response["success"] = true;
$response["data"] = [];
$response["data"]["entries"] = $entries;

echo json_encode($response);

?>
