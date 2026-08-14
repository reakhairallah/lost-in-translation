CREATE TABLE game_state (
    id                INT PRIMARY KEY DEFAULT 1,
    current_round_id  INT NULL,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_singleton CHECK (id = 1)
);
INSERT INTO game_state (id, current_round_id) VALUES (1, NULL);

CREATE TABLE seeds (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    text        VARCHAR(120) NOT NULL,
    char_count  INT NOT NULL,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE rounds (
    id                       INT AUTO_INCREMENT PRIMARY KEY,
    seed_id                  INT NOT NULL,
    status                   ENUM('generated','open','closed','revealed','aborted') NOT NULL DEFAULT 'generated',
    language_chain           VARCHAR(255) NOT NULL,
    duration_seconds         INT NOT NULL DEFAULT 60,
    opens_at                 DATETIME NULL,
    closes_at                DATETIME NULL,
    revealed_at              DATETIME NULL,
    triggered_by_session_id  VARCHAR(64) NULL,
    abort_reason             VARCHAR(255) NULL,
    failed_hop_index         INT NULL,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (seed_id) REFERENCES seeds(id)
);

CREATE TABLE chain_steps (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    round_id      INT NOT NULL,
    hop_index     INT NOT NULL,
    language_code VARCHAR(5) NOT NULL,
    text          VARCHAR(500) NOT NULL,
    char_count    INT NOT NULL,
    FOREIGN KEY (round_id) REFERENCES rounds(id),
    UNIQUE KEY uniq_round_hop (round_id, hop_index)
);

CREATE TABLE guesses (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    round_id        INT NOT NULL,
    session_id      VARCHAR(64) NOT NULL,
    guess_text      VARCHAR(255) NOT NULL,
    similarity      FLOAT NOT NULL,
    result          ENUM('correct','close','wrong') NOT NULL,
    points_awarded  INT NOT NULL,
    submitted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (round_id) REFERENCES rounds(id),
    UNIQUE KEY uniq_round_session_guess (round_id, session_id)
);

CREATE TABLE hints_used (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    round_id            INT NOT NULL,
    session_id          VARCHAR(64) NOT NULL,
    hint_number         INT NOT NULL,
    hop_index_revealed  INT NOT NULL,
    used_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (round_id) REFERENCES rounds(id),
    UNIQUE KEY uniq_round_session_hint (round_id, session_id, hint_number),
    CONSTRAINT chk_hint_cap CHECK (hint_number BETWEEN 1 AND 3)
);

CREATE TABLE hall_of_fame (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    round_id      INT NOT NULL,
    seed_id       INT NOT NULL,
    mangle_score  FLOAT NOT NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (round_id) REFERENCES rounds(id),
    FOREIGN KEY (seed_id) REFERENCES seeds(id)
);

CREATE TABLE votes (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    hof_id            INT NOT NULL,
    voter_session_id  VARCHAR(64) NOT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (hof_id) REFERENCES hall_of_fame(id),
    UNIQUE KEY uniq_vote (hof_id, voter_session_id)
);

CREATE TABLE translation_cache (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    source_text     VARCHAR(500) NOT NULL,
    source_lang     VARCHAR(5) NOT NULL,
    target_lang     VARCHAR(5) NOT NULL,
    translated_text VARCHAR(500) NOT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cache_lookup (source_text(191), source_lang, target_lang)
);