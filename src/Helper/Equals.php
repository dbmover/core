<?php

namespace Dbmover\Dbmover\Helper;

use Dbmover\Dbmover\ObjectInterface;

trait Equals
{
    protected function equals($obj1, $obj2)
    {
        if (!isset($obj1, $obj2)) {
            return false;
        }
        $obj1 = (array)$obj1;
        $obj2 = (array)$obj2;
        foreach ($obj1 as $key => $value) {
            if ($key{0} == '_') {
                continue;
            }
            if (!isset($obj2[$key])) {
                return false;
            }
            if (gettype($obj1[$key]) != gettype($obj2[$key])) {
                return false;
            }
            if (is_array($obj1[$key]) || is_object($obj1[$key])) {
                if (!$this->equals($obj1[$key], $obj2[$key])) {
                    return false;
                }
            }
            if ($obj1[$key] != $obj2[$key]) {
                return false;
            }
        }
        return true;
    }
}

