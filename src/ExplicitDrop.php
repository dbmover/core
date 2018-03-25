<?php

namespace Dbmover\Core;

class ExplicitDrop extends Plugin
{
    /** @var string */
    const DESCRIPTION = 'Explicitly dropping objects...';

    /**
     * @param string $sql
     * @return string
     */
    public function __invoke(string $sql) : string
    {
        foreach ($this->extractOperations('@^DROP .*?;$@ms', $sql) as $stmt) {
            $this->addOperation($stmt[0]);
        }
        foreach ($this->extractOperations('@^ALTER TABLE \w+ DROP CONSTRAINT .*?;$@ms', $sql) as $stmt) {
            $this->addOperation($stmt[0]);
        }
        return $sql;
    }
}

