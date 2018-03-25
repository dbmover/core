# Conditionals
Plugin to support _conditionals_ in your schema.

Normally when loading a full schema (e.g. `psql -u user -p database <
my-schema.sql`) you are not allowed to use `IF`/`ELSE` statements - these can
only appear inside of procedures. It goes without saying however that for more
complex migrations, sometimes you're going to want them. This plugin allows you
to include conditionals in your schemas without having to define procedures for
them.

## Usage
By itself this plugin doesn't do much; you'll usually want the vendor-specific
plugins (currently `dbmover/pgsql` or `dbmover/mysql` instead. These plugins
implement the wrapping logic so your `IF` statements are valid inside the schema
(essentially, they create a throwaway "lambda" function with your `IF`, run it
and discard it afterwards).

The `IF`s are run both on `__invoke` as well as on `__destruct`. It's up to you
to make sure they will never throw an error (e.g. check `information_schema` for
stuff first).

## Example
Say you want to rename table `foo` to `bar`. In your schema you can change the
table name, but that would cause DbMover to simply drop `foo` and create `bar`,
losing all data in `foo`. Generally not what you want. A workaround would be to
copy the definition for `bar` to `foo`, run DbMover, copy the data, remove the
definition for `bar` and run DbMover again - but this is the sort of manual work
DbMover aims to avoid...

A better strategy is to query `information_schema.tables` to see if the new
table exists yet, and if not perform the rename there:

```sql
IF NOT FOUND (SELECT * FROM information_schema.tables
    WHERE table_catalog = 'my_database_name'
        AND table_schema = 'public'
        AND table_name = 'bar')
THEN
    ALTER TABLE foo RENAME TO bar;
END IF;

-- This used to be `TABLE foo`:
CREATE TABLE bar (...);
```

## Note
This plugin is not part of any vendor-specific metapackage; you will always
need to add it manually to your `dbmover.json` config.

