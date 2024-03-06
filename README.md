# Autodb

<h3>This is a modified version of the original dszakal/autodb</h3>
<h3>This minimum requirement is now PHP 8.1.</h3>


LATEST SUPPORTED VERSION: PHP 8.3.3

CURRENT RELEASE NOT WORKING WITH: PHP 7.4 or previous

A very simple automated single table read-write Active Record Pattern implementation.

Old Stable: 000.030 (for previous PHP versions)

PostgreSQL support (php-pgsql (pg_connect, resource), NOT php-pdo-pgsql) from: 000.042

LIMITATIONS TO BE AWARE OF BEFORE YOU WOULD USE:

    This is not ORM. Just an active record pattern, it doesn't support joins on purpose.
    One AutoRecord instance = one row in one database's one table
    One AutoDb instance <-> One SQL database connection
    Static saveMore() and deleteMore() methods will only run on same AutoDb, same mysqli/pgsql resource, same table rows, otherwise throwing AutoDbException. (Empty array won't throw)
    Also save(), row(), rowsArray(), newRow(), saveMore() and deleteMore() are final for a reason
    Recommended usage - AutoRecord(s) as class member (composition), AutoDb (one per connection instance) as singleton or in any container globally available
    One Database, one Table two calls for Primary key -> use the same AutoDb instance and you will never have a duplicated AutoRecord instance
    Redis is an optional dependency for caching table describes.
    If using more AutoDb (more tables or more connections) with Redis table definition caching, you WANT to set different $ident for AutoDb::init()
    If using Redis cache you WANT to purge 'autodbdefs.*' redis keys in your deploy script, or at least you REALLY WANT TO delete it right after running an ALTER TABLE
    Concurrent writing (INSERT IGNORE INTO, REPLACE INTO) is supported, but only via AutoRecord::saveMore(array $arrayOfRecords) which will set saved inserted/replaced references as dead. For limitations see "unit" tests / concurrentWriteTests()
    We do not fully support editing of primary keys, but you should do that manually and carefully anyway
    Supports only databases with AUTO_INCREMENT (or Serial) POSITIVE INTEGER primary keys
    Everything by AutoDb or AutoRecord will throw a new instance of AutoDbException in all circumstances
    Supports MySQL through mysqli and PostgreSQL through pg_connect + resource
    PDO is not supported, and is not planned to be supported
    For now, type checking is very basic, will improve

Usage example:

```php
    <?php
    // create a new AutoDb instance connecting to your database connection. One mysqli resource <-> One AutoDb
    $autoDb = AutoDb::init($mysqli, $optionalRedis, 'optional_database_ident_for_redis_key_table_defs'); 
    // $mysqli is instanceof mysqli right after you connected
    // one $autoDb per connection is recommended, solve it with your own conatainer/singleton for best results
    
    //new record
    $rec = $autoDb->newRow('my_table'); // loads table definition once in runtime with describe 
    $rec->attr('some_column', 'newvalue');
    $rec->save();
    echo $rec->attr('my_table_id'); // gives you back the column value
    
    //existing record
    $rec = $autoDb->row('my_table', 'my_table_id', 23); // returns row with primary key my_table_id 23
    $rec->attr('some_column', 'changed_value');
    $rec->save();
    
    // for array of AutoRecord instances with one single query (and returns object cache version if exists)
    $arrayRecords = $autoDb->rowsArray($table, $where, $limit, $page); // limit default is -1 (unlimited results), page defaults to 1
    // BEWARE - 2nd parameter here is just an UNESCAPED string
    
    // You may also get a previous but unsaved value of an attr you already saved:
    echo $x->attr('username'); // 'changedPreviously'
    $x->attr('username', 'changedAgain');
    echo $x->attr('username'); // 'changedAgain'
    echo $x->dbAttr('username'); // 'changedPreviously' - from instance
    echo $x->dbAttrForce('username'); // 'changedPreviously' - from database
    $x->save(); // now all will be 'changedAgain'
    
    // During save() after INSERT/UPDATE there is no SELECT to populate the columns in the database. You can force it by calling $record->forceReloadAttributes(). 
    // (But primary key is up to date always after save(), even without calling $record->forceReloadAttributes() method)
    $row->attr('created_at', 'NOW()');
    $row->save();
    echo $row->attr('created_at');         // 'NOW()' :(
    echo $row->dbAttrForce('created_at');  // '2017-03-20 11:11:11'
    $row->forceReloadAttributes();         // the (not always required) extra query to sync column attributes
    echo $row->attr('created_at');         // '2017-03-20 11:11:11' :)
    
    // AutoDb also supports Read only, Write once and blocked tables:
    $autoDb->addBannedTable('sensitive_table');
    $autoDb->addWriteOnceTable('my_strict_log');
    $autoDb->addReadOnlyTable('categories');
    
    // You can insert more new rows with one single query optimally using 
    AutoRecord::saveMore($arrayOfSameTableAutorecords);
    // This line will also update one by one the lines already having a primary key
    
    // You can also delete in one single query multiple lines
    AutoRecord::deleteMore($arrayOfSameTableAutorecords);

    // if the mysqli resource dies and mysqli->ping() didn't help, you can force replacing it to a new connected mysqli instance
    $autoDb->replaceMysqliResource($reconnectedMysqli); // will recursively run through autorecords too
    
    // EXPERIMENTAL - untested strict "null only if NOT NULL allowed" mode
    $autoDb->setStrictNullableMode(true);
    
    // EXPERIMENTAL - get db dump of array of autorecord rows, (can even force primary key read)
    foreach ($records as $record) {
        $pk = $record->getPrimaryKeyValue();
        $record->rapePrimaryKeyForMultiInsertQuery($pk);
    }
        
    $sqlDump = AutoRecord::generateInsertQuery($records);
    // Records above may not be usable afterwards, even if yes, UNTESTED
```

```php
    // POSTGRESQL example:

    $pgResource = pg_connect('host=localhost port=5432 user=myuser password=mypassword dbname=mydb');

    $theOneAndOnlyAutoDbPerDatabase = AutoDb::init($pgResource);

    $row = $theOneAndOnlyAutoDbPerDatabase->row('mytable', 'mytable_id', 71);
    // all features work the same way as above in the mysql examples:
```


CONCURRENT WRITE SUPPORT

```php
    <?php
    // MySQL example:
    $row = MyAppContainer::getAutoDb()->newRow('unik');
    $row->attr('uniq_part_1', 'I_am_first_part_of_unique_key');
    $row->attr('uniq_part_2', 10);
    // $row->save(); // Would throw Exception if Unique key already exists (sometimes you want this though)
    
    // your options:
    AutoRecord::saveMore(array($row), 'INSERT IGNORE INTO'); // if there was a row using this unique key, that one wins
    AutoRecord::saveMore(array($row), 'REPLACE INTO'); // always the later write wins
    AutoRecord::saveMore(array($row), 'INSERT INTO', 'ON DUPLICATE KEY UPDATE request_count = request_count + 1'); // "manual"
    // YOU CANNOT USE $row AFTER ANY OF THESE, IT IS A DEAD REFERENCE, select again via rowsArray() by Unique keys, if you want to keep working with the row
    // REPLACE INTO also breaks previous record (by nature of mysql, that primary key value doesn't exist anymore)
    
    // after a concurrent write reload row by unique key, or you cannot work with it (dead reference):
    $row = MyAppContainer::getAutoDb()->rowsArray('unik', "uniq_part_1 = 'I_am_first_part_of_unique_key' AND uniq_part_2 = 10")[0]; // array[1] not set as unique
    
    // you may also want to get the query only the same way (new lines only, lines to update throw exception:
    AutoRecord::generateInsertQuery(array($row), 'INSERT INTO', 'ON DUPLICATE KEY UPDATE request_count = request_count + 1'); // return INSERT INTO ... string

    // For more details and limitations on MySQL concurrent write see tests/AutoDbTest.php method concurrentWriteTests()

    // PostGreSQL example:
    AutoRecord::saveMore(array($row1, $row2), 'INSERT INTO', '﻿ON CONFLICT (uniq_part_1, uniq_part_2) DO UPDATE SET request_count = mytable.request_count + 1;');
```

"UNIT" TESTS

```php
    <?php
    // to run "unit" tests (rather an Integration test) add to project root a file test_mysql_connection_credentials.php as stated in tests/bootstrap.php:
    // you may decide to run or not run MySQL and/or PostgreSQL based tests based on if that's being installed in your machine

    // GITIGNORED FILE:
    
    define('TEST_MYSQL', 1);
    define('MYSQL_HOST', 'localhost');
    define('MYSQL_USER', 'youruser');
    define('MYSQL_PASSWORD', 'yourpassword');


    define('TEST_PGSQL', 1);
    define('PGSQL_CONN_STRING', 'host=localhost port=5432 user=myuser password=mypassword'); // do not worry about conn db
    
```
