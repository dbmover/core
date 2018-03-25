<?php

/**
 * @package Dbmover
 * @subpackage Core
 */

namespace Dbmover\Core;

use PDO;

/**
 * Drop and re-add all foreign key constraints in the database. RDBMS-specific
 * plugins _must_ provide their own implementation of this, escpecially for the
 * `dropConstraint` method.
 */
abstract class Constraints extends Plugin
{
    /** @var string */
    const DESCRIPTION = 'Dropping existing constraints...';

    /** @var string */
    const DEFERRED = 'Recreating constraints...';

    /**
     * @param string $sql
     * @return string
     */
    public function __invoke(string $sql) : string
    {
        $stmt = $this->loader->getPdo()->prepare(
            "SELECT TABLE_NAME tbl, CONSTRAINT_NAME constr, CONSTRAINT_TYPE ctype
                FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
            WHERE (CONSTRAINT_CATALOG = ? AND CONSTRAINT_SCHEMA = 'public') OR CONSTRAINT_SCHEMA = ?"
        );
        $stmt->execute([$this->loader->getDatabase(), $this->loader->getDatabase()]);
        while (false !== ($constraint = $stmt->fetch(PDO::FETCH_ASSOC))) {
            if (!$this->loader->shouldBeIgnored($constraint['constr'])) {
                $this->dropConstraint($constraint['tbl'], $constraint['constr'], $constraint['ctype']);
            }
        }
        while ($constraint = $this->extractOperations("@^ALTER TABLE \S+ ADD FOREIGN KEY.*?;@ms", $sql)) {
            $this->defer($constraint[0]);
        }
        return $sql;
    }

    /**
     * @param string $table
     * @param string $constraint
     * @param string $type
     * @return void
     */
    protected abstract function dropConstraint(string $table, string $constraint, string $type) : void;
}

