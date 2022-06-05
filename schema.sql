CREATE TABLE tweets(
    id BIGINT NOT NULL PRIMARY KEY,
    author_id BIGINT NOT NULL,
    root_id BIGINT NOT NULL,
    `text` TEXT,
    source TEXT,
    created_at DATETIME NOT NULL,
    raw_object LONGTEXT,
    KEY root_id(root_id),
    KEY author_id(author_id)
);

CREATE TABLE authors(
    id BIGINT NOT NULL PRIMARY KEY,
    username VARCHAR(64) NOT NULL,
    name TEXT,
    description TEXT,
    avatar TEXT,
    location TEXT,
    verified TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    raw_object LONGTEXT
);

CREATE TABLE tweet_links(
    parent_id BIGINT NOT NULL,
    child_id BIGINT NOT NULL,
    link_type ENUM('reply', 'quote'),
    KEY parent_id(parent_id),
    KEY child_id(child_id),
    UNIQUE KEY idx_rel(parent_id, child_id)
);

CREATE TABLE tree_nodes(
    tree_id INT NOT NULL,
    tweet_id BIGINT NOT NULL,
    mptt_left INT NOT NULL,
    mptt_right INT NOT NULL,
    KEY tree_id(tree_id),
    KEY tweet_id(tweet_id),
    KEY mptt_left(mptt_left),
    KEY mptt_right(mptt_right)
);

CREATE TABLE trees(
    id INT NOT NULL PRIMARY KEY AUTO_INCREMENT,
    root_id BIGINT NOT NULL,
    requester_id BIGINT NOT NULL,
    views INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    deleted_at DATETIME
);

CREATE TABLE settings(
    setting_key VARCHAR(64) NOT NULL,
    setting_value TEXT
);
INSERT INTO settings(setting_key, setting_value) VALUES('last_mention', '0');
