<?php
declare(strict_types = 1);

$pdo = new PDO($_SERVER['DB_DSN']);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$command = NodeCommand::createFromCli($argv);
$nodeRepo = new NodeRepository($pdo);
$logger = new Logger();

try {
    switch($command->command) {
        case 'addNode':
            $name = $command->arguments[0];
            $parentId = $command->arguments[1] ?? null;
            $id = ($parentId === null)
                ? $nodeRepo->create($name)
                : $nodeRepo->create($name, (int) $parentId);
            $logger->add(sprintf('Node "%s" has been added with id #%d', $name, $id));
            break;
        case 'deleteNode':
            $id = $command->arguments[0];
            $nodeRepo->delete((int) $id);
            $logger->add(sprintf('Node id #%d has been deleted', $id));
            break;
        case 'renameNode':
            $id = $command->arguments[0];
            $name = $command->arguments[1];
            $node = $nodeRepo->getById((int)$id);
            $node->name = $name;
            $nodeRepo->update($node);
            $logger->add(sprintf('Node id #%d has been renamed to %s', $id, $name));
            break;
        case 'moveNode':
            $id = $command->arguments[0];
            $parentId = $command->arguments[1];
            $position = $command->arguments[2] ?? null;
            ($position === null)
                ? $nodeRepo->move((int) $id, (int) $parentId)
                : $nodeRepo->move((int) $id, (int) $parentId, (int) $position);
            $logger->add(sprintf('Node id #%d has been moved under node #%d', $id, $parentId));
            break;
    }
} catch (RuntimeException | InvalidArgumentException $e) {
    $logger->add($e->getMessage());
} catch (Throwable $e) {
    $logger->add('Something went wrong, sorry');
}

echo implode(PHP_EOL, $logger->logs);


class NodeCommand
{
    private const ALLOWED_COMMANDS = [
        'addNode',
        'deleteNode',
        'renameNode',
        'moveNode',
    ];

    private const ARGUMENTS_COUNTS = [
        'addNode' => 1,
        'deleteNode' => 1,
        'renameNode' => 2,
        'moveNode' => 2
    ];

    public $command;

    public $arguments;

    public static function createFromCli(array $cliArguments): self
    {
        $command = $cliArguments[1] ?? null;
        $arguments = array_slice($cliArguments, 2);

        if ($command === null) {
            throw new InvalidArgumentException('Please provide command to execute');
        }

        return new self((string) $command, $arguments);
    }

    private function __construct(string $command, array $arguments = [])
    {
        $this->assertValidCommand($command);
        $this->assertCorrectArgumentsCount($command, count($arguments));

        $this->command = $command;
        $this->arguments = $arguments;
    }

    private function assertValidCommand(string $command): void
    {
        if (!in_array($command, self::ALLOWED_COMMANDS, true)) {
            throw new InvalidArgumentException(
                sprintf('Unknown command: %s', $command)
            );
        }
    }

    private function assertCorrectArgumentsCount(string $command, int $count): void
    {
        $commandArgumentsCount = self::ARGUMENTS_COUNTS[$command] ?? null;

        if ($commandArgumentsCount !== null && $commandArgumentsCount > $count) {
            throw new InvalidArgumentException(
                sprintf('Command %s expects at least %d arguments, %d given')
            );
        }
    }
}


class NodeRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getById(int $id): Node
    {
        $stmt = $this->pdo->prepare(
        'SELECT 
            node.id, 
            node.name, 
            (SELECT id from nodes AS parent WHERE node.lft > parent.lft AND node.rgt < parent.rgt ORDER BY parent.lft LIMIT 1) AS parentId 
            FROM nodes AS node
            WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $node = $stmt->fetchObject(Node::class);

        if ($node === false) {
            throw new RuntimeException(
                sprintf('Node #%d not found', $id)
            );
        }

        return $node;
    }

    public function create(string $name, int $parentId = 1): int
    {
        $this->inTransaction(
            function () use ($name, $parentId) {
                $parentNode = $this->getById($parentId);

                // get the parent node's lft and rgt
                $getParentLftRgtStmt = $this->pdo->prepare(
                    'SELECT lft, rgt FROM nodes WHERE id = :parentId'
                );
                $getParentLftRgtStmt->bindValue(':parentId', $parentNode->id, PDO::PARAM_INT);
                $getParentLftRgtStmt->execute();
                [$parentLft, $parentRgt] = $getParentLftRgtStmt->fetchAll(PDO::FETCH_NUM)[0];

                $parentWidth = $parentRgt - $parentLft;

                if ($parentWidth > 2) {
                    $prevSiblingRgt = $parentRgt - 1;

                    $lft = $prevSiblingRgt + 1;
                    $rgt = $lft + 1;

                    // shift parent's rgt and all nodes to the right to 2 (excluding parent's children)
                    $shiftRgtStmt = $this->pdo->prepare('UPDATE nodes SET rgt = rgt + 2 WHERE rgt > :prevSiblingRgt');
                    $shiftRgtStmt->bindValue(':prevSiblingRgt', $prevSiblingRgt, PDO::PARAM_INT);
                    $shiftRgtStmt->execute();

                    $shiftLftStmt = $this->pdo->prepare('UPDATE nodes SET lft = lft + 2 WHERE lft > :prevSiblingRgt');
                    $shiftLftStmt->bindValue(':prevSiblingRgt', $prevSiblingRgt, PDO::PARAM_INT);
                    $shiftLftStmt->execute();
                } else {
                    $lft = $parentLft + 1;
                    $rgt = $lft + 1;

                    // shift parent's rgt and all nodes to the right to 2
                    $shiftRgtStmt = $this->pdo->prepare('UPDATE nodes SET rgt = rgt + 2 WHERE rgt > :parentLft');
                    $shiftRgtStmt->bindValue(':parentLft', $parentLft, PDO::PARAM_INT);
                    $shiftRgtStmt->execute();

                    $shiftLftStmt = $this->pdo->prepare('UPDATE nodes SET lft = lft + 2 WHERE lft > :parentLft');
                    $shiftLftStmt->bindValue(':parentLft', $parentLft, PDO::PARAM_INT);
                    $shiftLftStmt->execute();
                }

                // insert new node inside parent node
                $insertNodeStmt = $this->pdo->prepare(
                    'INSERT INTO nodes(name, lft, rgt) VALUES (:name, :lft, :rgt)'
                );
                $insertNodeStmt->bindValue(':name', $name);
                $insertNodeStmt->bindValue(':lft', $lft, PDO::PARAM_INT);
                $insertNodeStmt->bindValue(':rgt', $rgt, PDO::PARAM_INT);
                $insertNodeStmt->execute();
            }
        );

        return (int) $this->pdo->lastInsertId();
    }

    public function move(int $id, int $parentId, int $position = null): void
    {
        $this->inTransaction(
            function () use ($id, $parentId, $position) {
                // find node to move
                $node = $this->getById($id);

                // find node's lft and rgt
                $getLftRgtStmt = $this->pdo->prepare(
                    'SELECT lft, rgt FROM nodes WHERE id = :id'
                );
                $getLftRgtStmt->bindValue(':id', $node->id, PDO::PARAM_INT);
                $getLftRgtStmt->execute();
                [$lft, $rgt] = $getLftRgtStmt->fetchAll(PDO::FETCH_NUM)[0];

                // orphan moved node with all children
                $orphanStmt = $this->pdo->prepare(
                    'UPDATE nodes SET lft = lft * -1, rgt = rgt * -1 WHERE lft >= :lft AND rgt <= :rgt'
                );
                $orphanStmt->bindValue(':lft', $lft, PDO::PARAM_INT);
                $orphanStmt->bindValue(':rgt', $rgt, PDO::PARAM_INT);
                $orphanStmt->execute();

                // count width of moved node (including all nested nodes)
                $width = ($rgt - $lft) + 1;

                // shift left the parent node and all nodes to the right to the width of moved node
                $shiftLftStmt = $this->pdo->prepare(
                    'UPDATE nodes SET lft = lft - :shift WHERE lft > :rgt'
                );
                $shiftLftStmt->bindValue(':shift', $width, PDO::PARAM_INT);
                $shiftLftStmt->bindValue(':rgt', $rgt, PDO::PARAM_INT);
                $shiftLftStmt->execute();

                $shiftRgtStmt = $this->pdo->prepare(
                    'UPDATE nodes SET rgt = rgt - :shift WHERE rgt > :rgt'
                );
                $shiftRgtStmt->bindValue(':shift', $width, PDO::PARAM_INT);
                $shiftRgtStmt->bindValue(':rgt', $rgt, PDO::PARAM_INT);
                $shiftRgtStmt->execute();

                // find parent node
                $parentNode = $this->getById($parentId);

                // find lft and rgt of parent node
                $getParentLftRgtStmt = $this->pdo->prepare(
                    'SELECT lft, rgt FROM nodes WHERE id = :id'
                );
                $getParentLftRgtStmt->bindValue(':id', $parentNode->id, PDO::PARAM_INT);
                $getParentLftRgtStmt->execute();
                [$parentLft, $parentRgt] = $getParentLftRgtStmt->fetchAll(PDO::FETCH_NUM)[0];

                $parentWidth = $parentRgt - $parentLft;

                if (($parentWidth > 2) && ($position > 0)) {
                    if ($position !== null) {
                        if ($position < 0) {
                            throw new InvalidArgumentException('Position must not be less than 0');
                        }

                        // get rgt of prev sibling
                        $getPrevSiblingRgtStmt = $this->pdo->prepare(
                            'SELECT rgt FROM nodes WHERE lft > :parentLft AND rgt < :parentRgt ORDER BY lft LIMIT 1 OFFSET :offset'
                        );
                        $getPrevSiblingRgtStmt->bindValue(':parentLft', $parentLft, PDO::PARAM_INT);
                        $getPrevSiblingRgtStmt->bindValue(':parentRgt', $parentRgt, PDO::PARAM_INT);
                        $getPrevSiblingRgtStmt->bindValue(':offset', $position - 1, PDO::PARAM_INT);
                        $getPrevSiblingRgtStmt->execute();

                        $prevSiblingRgt = $getPrevSiblingRgtStmt->fetchColumn();
                    }

                    if (empty($prevSiblingRgt)) {
                        $prevSiblingRgt = $parentRgt - 1;
                    }

                    $newLft = $prevSiblingRgt + 1;

                    // shift parent's rgt and all nodes to the right to the width of moved node (excluding parent's children)
                    $shiftRgtStmt = $this->pdo->prepare('UPDATE nodes SET rgt = rgt + :shift WHERE rgt > :prevSiblingRgt');
                    $shiftRgtStmt->bindValue(':shift', $width, PDO::PARAM_INT);
                    $shiftRgtStmt->bindValue(':prevSiblingRgt', $prevSiblingRgt, PDO::PARAM_INT);
                    $shiftRgtStmt->execute();

                    $shiftLftStmt = $this->pdo->prepare('UPDATE nodes SET lft = lft + :shift WHERE lft > :prevSiblingRgt');
                    $shiftLftStmt->bindValue(':shift', $width, PDO::PARAM_INT);
                    $shiftLftStmt->bindValue(':prevSiblingRgt', $prevSiblingRgt, PDO::PARAM_INT);
                    $shiftLftStmt->execute();
                } else {
                    $newLft = $parentLft + 1;

                    // shift parent's rgt and all nodes to the right to the widht of moved node
                    $shiftRgtStmt = $this->pdo->prepare('UPDATE nodes SET rgt = rgt + :shift WHERE rgt > :parentLft');
                    $shiftRgtStmt->bindValue(':shift', $width, PDO::PARAM_INT);
                    $shiftRgtStmt->bindValue(':parentLft', $parentLft, PDO::PARAM_INT);
                    $shiftRgtStmt->execute();

                    $shiftLftStmt = $this->pdo->prepare('UPDATE nodes SET lft = lft + :shift WHERE lft > :parentLft');
                    $shiftLftStmt->bindValue(':shift', $width, PDO::PARAM_INT);
                    $shiftLftStmt->bindValue(':parentLft', $parentLft, PDO::PARAM_INT);
                    $shiftLftStmt->execute();
                }

                // count needed shift
                $shift = $newLft - $lft;

                // un-orphan and shift the orphaned node with children
                $unOrphanStmt = $this->pdo->prepare(
                    'UPDATE nodes SET lft = (lft * -1) + (:shift), rgt = (rgt * -1) + (:shift) WHERE lft < 0 AND rgt < 0'
                );
                $unOrphanStmt->bindValue(':shift', $shift, PDO::PARAM_INT);
                $unOrphanStmt->execute();
            }
        );
    }

    public function update(Node $node): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE nodes SET name = :name WHERE id = :id'
        );
        $stmt->bindValue(':name', $node->name);
        $stmt->bindValue(':id', $node->id, PDO::PARAM_INT);
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        $this->inTransaction(
            function() use ($id) {
                // check if node exists
                $node = $this->getById($id);

                // get lft and rgt of node
                $getLftRgtStmt = $this->pdo->prepare(
                    'SELECT lft, rgt FROM nodes WHERE id = :id'
                );
                $getLftRgtStmt->bindValue(':id', $node->id, PDO::PARAM_INT);
                $getLftRgtStmt->execute();
                [$lft, $rgt] = $getLftRgtStmt->fetchAll(PDO::FETCH_NUM)[0];

                // delete node with all nested nodes
                $deleteStmt = $this->pdo->prepare('DELETE FROM nodes WHERE lft >= :lft AND rgt <= :rgt');
                $deleteStmt->bindValue(':lft', $lft, PDO::PARAM_INT);
                $deleteStmt->bindValue(':rgt', $rgt, PDO::PARAM_INT);
                $deleteStmt->execute();

                // count width of deleted node
                $width = ($rgt - $lft) + 1;

                // shift parent node and all nodes to the right to the width of deleted node
                $shiftLftStmt = $this->pdo->prepare(
                    'UPDATE nodes SET lft = lft - :shift WHERE lft > :rgt'
                );
                $shiftLftStmt->bindValue(':shift', $width, PDO::PARAM_INT);
                $shiftLftStmt->bindValue(':rgt', $rgt, PDO::PARAM_INT);
                $shiftLftStmt->execute();

                $shiftRgtStmt = $this->pdo->prepare(
                    'UPDATE nodes SET rgt = rgt - :shift WHERE rgt > :rgt'
                );
                $shiftRgtStmt->bindValue(':shift', $width, PDO::PARAM_INT);
                $shiftRgtStmt->bindValue(':rgt', $rgt, PDO::PARAM_INT);
                $shiftRgtStmt->execute();
            }
        );
    }

    private function inTransaction(callable $func)
    {
        $this->pdo->exec('BEGIN EXCLUSIVE TRANSACTION');

        try {
            $result = $func();
        } catch (Throwable $e) {
            $this->pdo->exec('ROLLBACK');
            throw $e;
        }

        $this->pdo->exec('COMMIT');

        return $result;
    }
}


class Logger
{
    public $logs = [];

    public function add(string $logMsg): void
    {
        $this->logs[] = $logMsg;
    }
}

class Node
{
    public $id;

    public $parentId;

    public $name;
}
