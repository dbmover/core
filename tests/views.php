<?php

use Gentry\Gentry\Wrapper;
use Dbmover\Core\Loader;
use Dbmover\Core\Views;

/** Testsuite for Dbmover\Core\Views */
return function () : Generator {
    $loader = new Loader('mysql:dbname=dbmover_test', ['user' => 'dbmover_test', 'pass' => 'moveit'], true);
    $views = Wrapper::createObject(Views::class, $loader);
    /** Views get correctly stripped from a schema */
    yield function () use ($views) {
        $result = $views->__invoke('CREATE VIEW foo AS SELECT * FROM bar;');
        assert($result === '');
    };

};

