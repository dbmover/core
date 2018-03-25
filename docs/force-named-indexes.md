# ForceNamedIndexes
Plugin for DbMover that rewrites SQL to always have named indexes.

Some vendors like MySQL already require named indexes (at least, when defined
using `CREATE INDEX`), with for others (like PostgreSQL) the names are optional.

Using named indexes helps DbMover compare existing indexes and aids in avoiding
unnessecary index recration and thus longer migrations.

Indexes already explicitly named in your schemas remain untouched, so you'll
only need this plugin if you're lazy ;)

## Usage
You'll typically want to load this plugin before the (vendor's) `Indexes`
plugin. Note that e.g. PostgreSQL forks its `Indexes` from `Constraints`. It's
safe to add this plugin _before_ the vendor-specific meta plugin, since it only
rewrites your SQL where necessary; the actual index _creation_ is left to the
`Indexes` plugin.

## Workings
The plugin rewrites your SQL so each unnamed index will get an explicit name of
the form `TABLE_FIELD1_FIELD2_FIELDN_idx`. For complex index definitions like
possible in e.g. PostgreSQL, it also strips all non-word characters
(technically, replace `"\W+"` with `"_"`).

## Caveats and gotchas
- Vendors may impose limits on index name lengths and silently truncate overly
  long names. In these cases, obviously, DbMover will see them as different
  indexes. The solution is to explicitly name these indexes.
- Some vendors (e.g. PostgreSQL) allow indexes with a `WHERE` clause. If the
  used fields are the same as another index, DbMover will only see the last one
  defined (after all, the implicit names will be the same). Again, the solution
  is to explicitly name these indexes (e.g. `users_male` and `users_female` for
  indexes on `users(gender)` where `gender = 'm'` and `gender='f'`).

Again, it's always better to explicitly name indexes just in case. But good
programmers are lazy...

## Note
This plugin is not part of any vendor-specific metapackage; you will always
need to add it manually to your `dbmover.json` config.

