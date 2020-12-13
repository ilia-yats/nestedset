# nestedset

### Script for managing a hierahical structure in relational database (sqlite)

**Initialize DB:**
  
    DB_DSN="sqlite:nodes.db" php initdb.php

**Add node:**
  
    DB_DSN="sqlite:nodes.db" php nodemanage.php addNode {nodeName}

**Delete node (with all children):**

    DB_DSN="sqlite:nodes.db" php nodemanage.php deleteNode {nodeID}

**Rename node:**
  
    DB_DSN="sqlite:nodes.db" php nodemanage.php renameNode {nodeID} {newName}

**Move node (with all children) under new parent (will be append to the list of current children):**

    DB_DSN="sqlite:nodes.db" php nodemanage.php moveNode {movedNodeID} {newParentID}

**Move node (with all children) under new parent on exact position:**

    DB_DSN="sqlite:nodes.db" php php nodemanage.php moveNode {movedNodeID} {newParentID} {position}
