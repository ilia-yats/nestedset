<?php
$pdo = new PDO($_SERVER['DB_DSN']);
$pdo->exec('DELETE FROM nodes WHERE id <> 1; UPDATE nodes SET lft = 1, rgt = 2 WHERE id = 1');
