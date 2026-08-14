<?php

// Shared normalization + comparison, used by both guess scoring and mangle scoring.
// Never duplicate this logic elsewhere — always call similarity().
function similarity(string $a, string $b): float {
    $a = strtolower($a);
    $b = strtolower($b);

    $a = preg_replace('/[^\p{L}\p{N}\s]/u', '', $a);
    $b = preg_replace('/[^\p{L}\p{N}\s]/u', '', $b);

    $a = trim(preg_replace('/\s+/', ' ', $a));
    $b = trim(preg_replace('/\s+/', ' ', $b));

    if ($a === '' && $b === '') {
        return 1.0;
    }

    $maxLen = max(strlen($a), strlen($b));
    if ($maxLen === 0) {
        return 1.0;
    }

    $distance = levenshtein($a, $b);

    return 1.0 - ($distance / $maxLen);
}

?>
