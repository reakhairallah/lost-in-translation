<?php

function getSessionId(): string {
    if(isset($_COOKIE["session_id"])){
        return $_COOKIE["session_id"];
    }

    $session_id = bin2hex(random_bytes(16));

    setcookie("session_id", $session_id, time() + (60 * 60 * 24 * 365), "/");
    $_COOKIE["session_id"] = $session_id;

    return $session_id;
}

?>
