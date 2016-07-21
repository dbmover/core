<?php

namespace Dbmover\Dbmover;

trait ProcedureWrapper
{
    /**
     * Database vendors not allowing direct conditionals (e.g. MySQL) can wrap
     * them in an "anonymous" procedure ECMAScript-style here.
     *
     * @param string $sql The SQL to wrap.
     * @return string The input SQL potentially wrapped and called.
     */
    public function wrapInProcedure($sql)
    {
        return $sql;
    }
}

