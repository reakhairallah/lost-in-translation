<?php

include("connection.php");

$idioms = [
    "It's raining cats and dogs.",
    "Break a leg.",
    "Bite the bullet.",
    "Spill the beans.",
    "Let the cat out of the bag.",
    "Kick the bucket.",
    "It's a piece of cake.",
    "That cost an arm and a leg.",
    "You hit the nail on the head.",
    "I'm feeling under the weather.",
    "That only happens once in a blue moon.",
    "You're barking up the wrong tree.",
    "Stop beating around the bush.",
    "Cat got your tongue?",
    "Curiosity killed the cat.",
    "Don't cry over spilled milk.",
    "Every cloud has a silver lining.",
    "I'm going to hit the sack.",
    "It takes two to tango.",
    "Everyone jumped on the bandwagon.",
    "Let sleeping dogs lie.",
    "I said it off the top of my head.",
    "She's really on the ball today.",
    "Stop pulling my leg.",
    "We finally see eye to eye.",
    "Speak of the devil.",
    "He tried to steal my thunder.",
    "Take that rumor with a grain of salt.",
    "The ball is in your court now.",
    "He decided to throw in the towel.",
    "The deal was made under the table.",
    "That will happen when pigs fly.",
    "Don't judge a book by its cover.",
    "Stop adding fuel to the fire.",
    "She burned the midnight oil all week.",
    "The contractor tried to cut corners.",
    "He got a taste of his own medicine.",
    "We had to go back to the drawing board.",
    "Hold your horses, we're not done yet.",
    "He really missed the boat on that one.",
];

foreach ($idioms as $text) {
    $char_count = strlen($text);

    if ($char_count > 120) {
        echo "Skipping (over 120 chars, $char_count): $text\n";
        continue;
    }

    $sql = "SELECT id FROM seeds WHERE text = ?";
    $query = $mysql->prepare($sql);
    $query->bind_param("s", $text);
    $query->execute();
    $array = $query->get_result();
    $existing = $array->fetch_assoc();

    if ($existing != null) {
        echo "Skipping (already exists): $text\n";
        continue;
    }

    $is_active = true;

    $sql = "INSERT INTO seeds(text, char_count, is_active) VALUES(?, ?, ?)";
    $query = $mysql->prepare($sql);
    $query->bind_param("sii", $text, $char_count, $is_active);
    $query->execute();

    echo "Inserted: $text\n";
}

?>
