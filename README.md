# autodb

NOT READY YET, please ignore

A very simple automated single table read-write Active Record Pattern implementation.

LIMITATIONS TO BE AWARE OF BEFORE YOU WOULD USE:
    This is not ORM. Just an active record pattern, it doesn't support joins on purpose. 
    Also save(), row(), rowsArray() and newRow() are final for a reason
    For now, supports only MySQL
    For now, supports only databases with auto_increment positive integer primary keys (planned to work with unique keys too)
    For now, we do not support editing of primary keys, but you should do that manually and carefully anyway
    For now, type checking is very basic, will improve

Usage:
    // create a new row
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
    
