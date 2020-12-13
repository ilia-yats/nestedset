<?php
$pdo = new PDO($_SERVER['DB_DSN']);
$pdo->exec('
    CREATE TABLE nodes(
        id INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        lft INTEGER NOT NULL,
        rgt INTEGER NOT NULL
    );
    
    CREATE INDEX lft_idx ON nodes(lft);
    CREATE INDEX rgt_idx ON nodes(rgt);
    
    INSERT INTO nodes(id, name, lft, rgt) VALUES(1, "root", 1, 2);
');