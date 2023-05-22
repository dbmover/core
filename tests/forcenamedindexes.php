<?php

use Gentry\Gentry\Wrapper;
use Dbmover\Core\Loader;

/** Testsuite for Dbmover\Core\ForceNamedIndexes */
return function () : Generator {
    $loader = new Loader('mysql:dbname=dbmover_test', ['user' => 'dbmover_test', 'pass' => 'moveit'], true);
    $object = new Wrapper(new Dbmover\Core\ForceNamedIndexes($loader));
    /** An unnamed index gets named correctly */
    yield function () use ($object) {
        $result = $object->__invoke('CREATE INDEX ON foo(bar);');
        assert($result === 'CREATE INDEX foo_bar_idx ON foo(bar);');
    };
    /** A normal index gets left alone */
    yield function () use ($object) {
        $result = $object->__invoke('CREATE INDEX baz ON foo(bar);');
        assert($result === 'CREATE INDEX baz ON foo(bar);');
    };
};

