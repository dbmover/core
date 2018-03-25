<?php

/**
 * @package Dbmover
 * @subpackage Core
 * @subpackage Conditionals
 */

namespace Dbmover\Core;

/**
 * Gather all conditionals and optionally wrap them in a "lambda".
 */
class Conditionals extends Plugin
{
    const DESCRIPTION = 'Executing conditional blocks...';

    public function __invoke(string $sql) : string
    {
        foreach ($this->extractOperations('@^IF.*?^END IF;$@ms', $sql) as $if) {
            $code = $this->wrap($if[0]);
            $this->defer($code);
            $this->addOperation($code);
        }
        return $sql;
    }

    protected function wrap(string $sql) : string
    {
        return $sql;
    }
}

