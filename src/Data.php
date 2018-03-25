<?php

/**
 * @package Dbmover
 * @subpackage Core
 */

namespace Dbmover\Core;

/**
 * Plugin to handle default data statements (`INSERT` etc.). Note this currently
 * does not check for existence (this is sort-of a todo). Authors are
 * responsible for checking this themselves (`IF NOT FOUND`).
 */
class Data extends Plugin
{
    /** @var string */
    const DESCRIPTION = 'Handling default data...';

    /**
     * @param string $sql
     * @return string
     */
    public function __invoke(string $sql) : string
    {
        foreach ($this->extractOperations("@^(INSERT INTO|UPDATE|DELETE FROM).*?;$@ms", $sql) as $statement) {
            $this->defer($statement[0]);
        }
        return $sql;
    }
}

