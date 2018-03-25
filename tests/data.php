<?php

use Gentry\Gentry\Wrapper;
use Dbmover\Core\Loader;
use Dbmover\Core\Data;

/** Testsuite for Dbmover\Core\Data */
return function () : Generator {
    $loader = new Loader('mysql:dbname=dbmover_test', ['user' => 'dbmover_test', 'pass' => 'moveit'], true);
    $data = Wrapper::createObject(Data::class, $loader);
    /** Insert statements get stripped */
    yield function () use ($data) {
        $result = $data->__invoke("INSERT INTO foo VALUES ('bar');");
        assert($result === '');
    };
    /** Update statements get stripped */
    yield function () use ($data) {
        $result = $data->__invoke("UPDATE foo SET bar = 'baz';");
        assert($result === '');
    };
    /** Delete statements get stripped */
    yield function () use ($data) {
        $result = $data->__invoke("DELETE FROM foo WHERE 1 = 1;");
        assert($result === '');
    };
};

