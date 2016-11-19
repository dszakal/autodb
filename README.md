# Autodb

A very simple automated single table read-write Active Record Pattern implementation.

LIMITATIONS TO BE AWARE OF BEFORE YOU WOULD USE:

    This is not ORM. Just an active record pattern, it doesn't support joins on purpose.
    One AutoRecord instance = one row in one database's one table
    One AutoDb instance <-> One SQL database connection
    Static saveMore() and deleteMore() methods will only run on same AutoDb, same mysqli, same table rows, otherwise quitting
    Also save(), row(), rowsArray(), newRow(), saveMore() and deleteMore() are final for a reason
    Recommended usage - AutoRecord(s) as class member (composition), AutoDb as singleton/container (one per connection instance) globally available
    One Database, one Table two calls for Primary key -> use the same AutoDb instance and you will never have a duplicated AutoRecord instance
    Redis is an optional dependency for caching table describes.
    If using more AutoDb (more tables or more connections) with Redis table definition caching, you WANT to set different $ident for AutoDb::init()
    If using Redis cache you WANT to purge 'autodbdefs.*' redis keys in your deploy script, or at least you REALLY WANT TO delete it right after running an ALTER TABLE
    For now, supports only MySQL - with mysqli (PDO planned to be supported very soon)
    For now, supports only databases with auto_increment positive integer primary keys (planned to work with unique keys too)
    For now, we do not support editing of primary keys, but you should do that manually and carefully anyway
    For now, type checking is very basic, will improve

Usage example:

```php
    <?php
    // create a new AutoDb instance connecting to your database connection. One mysqli resource <-> One AutoDb
    $autoDb = AutoDb::init($mysqli, $optionalRedis); 
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
    $arrayRecords = $autoDb->rowsArray($table, $where, $limit, $page); // limit default is 100, page defaults to 1 which returns 0-100
    // this feature is not tested yet, beware
    
    // You may also get a previous but unsaved value of an attr you already saved:
    echo $x->attr('username'); // 'changedPreviously'
    $x->attr('username', 'changedAgain');
    echo $x->attr('username'); // 'changedAgain'
    echo $x->dbAttr('username'); // 'changedPreviously' - from instance
    echo $x->dbAttrForce('username'); // 'changedPreviously' - from database
    $x->save(); // now all will be 'changedAgain'
    
    // AutoDb also supports Read only, Write once and blocked tables:
    $autoDb->addBannedTable('sensitive_table');
    $autoDb->addWriteOnceTable('my_strict_log');
    $autoDb->addReadOnlyTable('categories');
    
    // You can instantiate more AutoRecord rows with one query:
    $rows = $autoDb->rowsArray('my_table', "'my_column' = 'value'");
    // BEWARE - 2nd parameter here is just an UNESCAPED string
    
    // You can insert more new rows with one single query optimally using 
    AutoRecord::saveMore($arrayOfSameTableAutorecords);
    // This line will also update one by one the lines already having a primary key
    
    // You can also delete in one single query multiple lines
    AutoRecord::deleteMore($arrayOfSameTableAutorecords);
```
