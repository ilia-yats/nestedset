<?php

$pdo = new PDO($_SERVER['DB_DSN']);

$nodesDepths = $pdo->query(
'SELECT node.id, node.name, (COUNT(parent.name) - 1) AS depth
    FROM nodes AS node, nodes AS parent
    WHERE node.lft BETWEEN parent.lft AND parent.rgt
    GROUP BY node.id
    ORDER BY node.lft;'
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($nodesDepths as $nodeDepth) {
    $id = $nodeDepth['id'];
    $name = $nodeDepth['name'];
    $depth = $nodeDepth['depth'];

    echo str_repeat('    ', $depth), $name, " ($id)", PHP_EOL;
}