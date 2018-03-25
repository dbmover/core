<?php

/**
 * @package Dbmover
 * @subpackage Core
 */

namespace Dbmover\Core;

use PDO;

class Procedures extends Plugin
{
    const REGEX = '@^CREATE (PROCEDURE|FUNCTION).*?^END;$@ms';
    const DROP_ROUTINE_SUFFIX = '()';

    /** @var string */
    const DESCRIPTION = 'Dropping existing procedures...';

    /** @var string */
    const DEFERRED = 'Recreating procedures...';

    /**
     * @param string $sql
     * @return string
     */
    public function __invoke(string $sql) : string
    {
        $this->dropExistingProcedures();
        while ($procedure = $this->extractOperations(static::REGEX, $sql)) {
            $this->defer($procedure[0]);
        }
        return $sql;
    }

    /**
     * @return void
     */
    protected function dropExistingProcedures() : void
    {
        $stmt = $this->loader->getPdo()->prepare(sprintf(
            "SELECT
                ROUTINE_TYPE routinetype,
                ROUTINE_NAME routinename
            FROM INFORMATION_SCHEMA.ROUTINES WHERE
                (ROUTINE_CATALOG = '%1\$s' OR ROUTINE_SCHEMA = '%1\$s')",
            $this->loader->getDatabase()
        ));
        $stmt->execute();
        while (false !== ($routine = $stmt->fetch(PDO::FETCH_ASSOC))) {
            if (!$this->loader->shouldBeIgnored($routine)) {
                $this->addOperation(sprintf(
                    "DROP %s %s%s",
                    $routine['routinetype'],
                    $routine['routinename'],
                    static::DROP_ROUTINE_SUFFIX
                ));
            }
        }
    }
}

