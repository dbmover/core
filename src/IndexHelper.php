<?php

namespace Dbmover\Dbmover;

trait IndexHelper
{
    /**
     * Returns an array of [tbl, idx] hashes with index names and the tables
     * they were specified on.
     *
     * @return array Array of hashes with index information.
     */
    public abstract function getIndexes();

    /**
     * Generate drop statements for all indexes in the database.
     *
     * @return array Array of SQL operations.
     */
    public abstract function dropIndexes();
}

