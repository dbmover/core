<?php

use Gentry\Gentry\Wrapper;

/** Testsuite for Dbmover\Core\ForceNamedIndexes */
return function () : Generator {
    $object = Wrapper::createObject(Dbmover\Core\ForceNamedIndexes::class);
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

