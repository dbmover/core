<?php

/**
 * @package Dbmover
 * @subpackage Core
 */

namespace Dbmover\Core;

class ForceNamedIndexes extends Plugin
{
    const DESCRIPTION = 'Rewriting indexes to named indexes...';

    public function __invoke(string $sql) : string
    {
        if (preg_match_all(IndexesAndConstraints::REGEX, $sql, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (!strlen(trim($match[2]))) {
                    $name = preg_replace("@[\W_]+@", '_', "{$match[3]}_".strtolower($match[5])).'_idx';
                    $_sql = str_replace('INDEX ON', "INDEX $name ON", $match[0]);
                    $sql = str_replace($match[0], $_sql, $sql);
                }
            }
        }
        return $sql;
    }
}

