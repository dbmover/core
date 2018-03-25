# Data
Plugin to execute data-altering statements (`INSERT`, `UPDATE`, `DELETE`) on
migration.

## Usage
This plugin extracts and executes all data-altering statements from your schema
file. Typically, this will be the last plugin you'll want to add.

## Example
Say you have a table `foo` in your schema, with many rows. You now add a table
`bar` in a migration, and your application requires that it contains a row for
each row in `foo` (perhaps you're doing a straight join in the new version
somewhere). You could of course "remember" to perform that insert after
migration, but that sucks (and people will forget).

_Or_ you could include something like this in your schema:

``sql
INSERT INTO bar (id, bar) SELECT id, foo FROM foo WHERE id NOT IN
    (SELECT id FROM bar);
```

## Note
This plugin is not part of any vendor-specific metapackage; you will always
need to add it manually to your `dbmover.json` config.

