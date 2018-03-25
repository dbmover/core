# DbMover\Conditionals
SQL extension plugin to support _conditionals_ in your schema.

Normally when loading a full schema (e.g. `psql -u user -p database <
my-schema.sql`) you are not allowed to use `IF`/`ELSE` statements - these can
only appear inside of procedures. It goes without saying however that for more
complex migrations, sometimes you're going to want them.

## Installation

```sh
$ composer require dbmover/conditionals
```

## Usage
For general DbMover usage, see `dbmover/core`.

By itself this package doesn't do much; you'll usually want the vendor-specific
plugins (currently `dbmover/pgsql-conditionals` or `dbmover/mysql-conditionals`
instead. These plugins implement the wrapping logic so your `IF` statements are
valid inside the schema (essentially, they create a throwaway "lambda" function
with your `IF`, run it and discard it afterwards).

The `IF`s are run both on `__invoke` as well as on `__destruct`. It's up to you
to make sure they will never throw an error (e.g. check `information_schema` for
stuff first).

## Example
Say you want to rename table `foo` to `bar`. In your schema you can change the
table name, but that would cause DbMover to simply drop `foo` and create `bar`,
losing all data in `foo`. Generally not what you want. A workaround would be to
copy the definition for `bar` to `foo`, run DbMover, copy the data, remote the
definition for `bar` and run DbMover again - but this is the sort of manual work
DbMover aims to avoid...

A better strategy is to query `information_schema.tables` to see if the new
table exists yet, and if not perform the rename there:

```sql
IF NOT FOUND (SELECT * FROM information_schema.tables
    WHERE table_catalog = DBMOVER_DATABASE AND table_schema = 'public')
THEN
    ALTER TABLE foo RENAME TO bar;
END IF;
```

Note that the lambda has access to the text variable `DBMOVER_DATABASE`
automatically. It contains the name of the database DbMover is currently
operationg on. This is handy for when you have multiple databases sharing a
single schema file (e.g. related projects with just a few overrides).

## Contributing
See `dbmover/core`.

