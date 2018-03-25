<?php

use Gentry\Gentry\Wrapper;
use Dbmover\Core\Loader;

/** Testsuite for Dbmover\Core\ExplicitDrop */
return function () : Generator {
    $loader = new Loader('mysql:dbname=dbmover_test', ['user' => 'dbmover_test', 'pass' => 'moveit'], true);
    $object = Wrapper::createObject(Dbmover\Core\ExplicitDrop::class, $loader);
    /** Explicit drop statements get extracted */
    yield function () use ($object) {
        $result = $object->__invoke('DROP TABLE blarps;');
        assert($result === '');
    };

};

