<?php

namespace Dbmover\Dbmover\Helper;

trait Ns
{
    public function getObjectName($suffix)
    {
        $class = 'Dbmover\\'.getenv('DBMOVER_VENDOR')."\\Objects\\$suffix";
        if (class_exists($class)) {
            return $class;
        } else {
            return "Dbmover\\Dbmover\\Objects\\$suffix";
        }
    }
}

