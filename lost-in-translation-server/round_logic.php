<?php

include("temp/translation_chain.php");
include("scoring.php");

function createRound(): int {
    global $mysql;

    $languageChain = ['ja','ar','fi','sw','hu','ko'];
    $language_chain_str = implode(",", $languageChain);

    $sql = "SELECT id, text FROM seeds WHERE is_active = 1 ORDER BY RAND() LIMIT 1";
    $query = $mysql->prepare($sql);
    $query->execute();
    $array = $query->get_result();
    $seed = $array->fetch_assoc();

    $seed_id = $seed["id"];
    $seed_text = $seed["text"];

    $result = runTranslationChain($seed_text, $languageChain);

    if (!$result["success"]) {
        $error = $result["error"];
        $failed_hop_index = $result["failed_hop_index"];

        $sql = "INSERT INTO rounds(seed_id, status, language_chain, duration_seconds, abort_reason, failed_hop_index) VALUES(?, 'aborted', ?, 60, ?, ?)";
        $query = $mysql->prepare($sql);
        $query->bind_param("issi", $seed_id, $language_chain_str, $error, $failed_hop_index);
        $query->execute();

        return $mysql->insert_id;
    }

    $sql = "INSERT INTO rounds(seed_id, status, language_chain, duration_seconds) VALUES(?, 'generated', ?, 60)";
    $query = $mysql->prepare($sql);
    $query->bind_param("is", $seed_id, $language_chain_str);
    $query->execute();

    $round_id = $mysql->insert_id;

    foreach ($result["steps"] as $step) {
        $hop_index = $step["hop_index"];
        $language_code = $step["language_code"];
        $step_text = $step["text"];
        $char_count = strlen($step_text);

        $sql = "INSERT INTO chain_steps(round_id, hop_index, language_code, text, char_count) VALUES(?, ?, ?, ?, ?)";
        $query = $mysql->prepare($sql);
        $query->bind_param("iissi", $round_id, $hop_index, $language_code, $step_text, $char_count);
        $query->execute();
    }

    return $round_id;
}

function openRound(int $roundId): void {
    global $mysql;

    $sql = "UPDATE rounds SET status = 'open', opens_at = NOW(), closes_at = NOW() + INTERVAL duration_seconds SECOND WHERE id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $roundId);
    $query->execute();

    $sql = "SELECT current_round_id FROM game_state WHERE id = 1";
    $query = $mysql->prepare($sql);
    $query->execute();
    $array = $query->get_result();
    $state = $array->fetch_assoc();
    $old_current_round_id = $state["current_round_id"];

    // Compare-and-swap: only advance game_state if it still matches what we
    // expect. <=> is MySQL's null-safe equals, since current_round_id may be NULL.
    $sql = "UPDATE game_state SET current_round_id = ? WHERE current_round_id <=> ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("ii", $roundId, $old_current_round_id);
    $query->execute();

    if ($mysql->affected_rows === 0) {
        // Someone else already advanced game_state first — not an error, just note it.
        error_log("openRound: game_state already advanced past expected current_round_id=" . var_export($old_current_round_id, true));
    }
}

function closeRound(int $roundId): void {
    global $mysql;

    $sql = "UPDATE rounds SET status = 'closed' WHERE id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $roundId);
    $query->execute();
}

function revealRound(int $roundId): void {
    global $mysql;

    $sql = "SELECT r.seed_id, s.text AS seed_text FROM rounds r JOIN seeds s ON s.id = r.seed_id WHERE r.id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $roundId);
    $query->execute();
    $round = $query->get_result()->fetch_assoc();

    $seed_id = $round["seed_id"];
    $seed_text = $round["seed_text"];

    $sql = "SELECT text FROM chain_steps WHERE round_id = ? ORDER BY hop_index DESC LIMIT 1";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $roundId);
    $query->execute();
    $final_step = $query->get_result()->fetch_assoc();
    $final_text = $final_step["text"];

    $mangle_score = (1 - similarity($seed_text, $final_text)) * 100;

    $sql = "UPDATE rounds SET status = 'revealed', revealed_at = NOW() WHERE id = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("i", $roundId);
    $query->execute();

    $sql = "INSERT INTO hall_of_fame(round_id, seed_id, mangle_score) VALUES(?, ?, ?)";
    $query = $mysql->prepare($sql);
    $query->bind_param("iid", $roundId, $seed_id, $mangle_score);
    $query->execute();
}

?>
