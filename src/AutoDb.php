<?php

namespace AutoDb;
use mysqli;
use AutoDb\AutoDbException;
use Redis;

class AutoDb {
    
    /**
     * // array of AutoRecord->_columnRules
     * @var array - example $this->_tableDefs[$tablename][$column1]['primary_key']
     */
    private $_tableDefs = array(); // TODO
    
    private $_redisInstance;
    
    /** 
     *
     * @var mysqli
     */
    private $_sqlResource;
    
    public $redisTimeout = 3600;
    
    // read and write limitation rules per table
    private $_bannedTables = array();
    private $_readOnlyTables = array();
    private $_writeOnceTables = array(); // No update allowed
    private $_strictNullableMode = false; // default - for compatibility and multinserts' safety (DEFAULT values)
    
    // reconnect mysqli where mysqli->ping() is unavailable or not working
    private $_sqlResReplacing = false;
    
    // on destruct we may force disconnect:
    private $_onDestructDisconnect = false;
    
    /**
     *
     * @var string - for the redis key
     */
    private $_connectionIdent = 'default';
    
    private $_recordInstances = array(); // only one reference should exist for all primary key
    
    protected function __construct($sqlResource, $redisInstance = null, $connectionIdent = 'default') {
        $this->_sqlResource = $sqlResource;
        $this->_redisInstance = $redisInstance;
        $this->_connectionIdent = $connectionIdent;
    }
    
    /**
     * 
     * @param mysqli $sqlResource - one MySQL connection <-> One AutoDb instance
     * @param type $redisInstance - optional, but instance of Redis if set
     * @param type $connectionIdent - if there are more connections/databases (== more AutoDb instances, we want to avoid redis table key clashing on defs
     * @return \AutoDb\AutoDb
     * @throws AutoDbException
     */
    public static function init($sqlResource, $redisInstance = null, $connectionIdent = 'default') {
        if (!($sqlResource instanceof mysqli) && !(static::isPgsqlResource($sqlResource))) {
            throw new AutoDbException('AutoDB/AutoRecord: Only mysqli object and postgresql resource functionality is implemented');
        }
        return new AutoDb($sqlResource, $redisInstance, $connectionIdent);
    }
    
    public function getTableDefs() {
        return $this->_tableDefs;
    }

    public function getRedisInstance() {
        return $this->_redisInstance;
    }

    public function getSqlResource() {
        return $this->_sqlResource;
    }
    
    public function getRecordInstances() {
        return $this->_recordInstances;
    }
    
    public function getBannedTables() {
        return $this->_bannedTables;
    }

    public function getReadOnlyTables() {
        return $this->_readOnlyTables;
    }

    public function getWriteOnceTables() {
        return $this->_writeOnceTables;
    }

    public function getConnectionIdent() {
        return $this->_connectionIdent;
    }
    
    public function getSqlResReplacing() {
        return $this->_sqlResReplacing;
    }
    
    public function getStrictNullableMode() {
        return $this->_strictNullableMode;
    }
    
    public function setStrictNullableMode($value) {
        $this->_strictNullableMode = (bool)$value;
        return $this;
    }
    
    public function addBannedTable($tablename) {
        $this->_bannedTables[$tablename] = $tablename;
    }
    
    public function addReadOnlyTable($tablename) {
        $this->_readOnlyTables[$tablename] = $tablename;
    }
    
    public function addWriteOnceTable($tablename) {
        $this->_writeOnceTables[$tablename] = $tablename;
    }
    
    public function setOnDestructDisconnect($bool)
    {
        $this->_onDestructDisconnect = (bool)$bool;
    }    
    
    /**
     * Create a new instance of a later possible row in the database
     * Final - Just AutoRecord::loadRow($this, $table, $keyname, null);
     * 
     * @param type $table
     * @param type $keyname
     * @param type $value
     * @return AutoRecord
     */
    public final function newRow($table) 
    {
        return AutoRecord::loadRow($this, $table, null);
    }
    
    /**
     * Get a row from the db: existing reference or load if not exists
     * Final - Just AutoRecord::loadRow($this, $table, $keyname, $value);
     * 
     * @param type $table
     * @param type $keyname
     * @param type $value
     * @return AutoRecord
     */
    public final function row($table, $keyname, $value) 
    {
        return AutoRecord::loadRow($this, $table, $keyname, $value);
    }
    
    /**
     * Get a set of rows in an array based on a where condition.
     * Final - Just AutoRecord::loadRows($this, $table, $where, $limit, $page);
     * 
     * @param type $table
     * @param type $where
     * @param type $limit
     * @param type $page
     * @return array - array of AutoRecord instances
     */
    public final function rowsArray($table, $where, $limit = -1, $page = 1) 
    {
        return AutoRecord::loadRowsWhere($this, $table, $where, $limit, $page);
    }
    
    /**
     * DO NOT USE, unless you are AutoRecord class loadRow and loadRowsWhere method
     * Needs to be public as this is not C++, no friend classes in PHP :(
     * 
     * @param \AutoDb\AutoRecord $record
     */
    public final function _addInstance(AutoRecord $record)
    {
        $this->_recordInstances[$record->getTableName()][$record->getPrimaryKeyValue()] = $record;
    }
    
    public final function _removeKey($tablename, $primarykey)
    {
        $this->_recordInstances[$tablename][$primarykey] = null;
        unset($this->_recordInstances[$tablename][$primarykey]);
    }
    
    public function getTableDef($tablename) 
    {
        // first check current instance
        if (isset($this->_tableDefs[$tablename])) {
            return $this->_tableDefs[$tablename];
        }
        
        // check redis if any
        if ($this->_redisInstance instanceof Redis) {
            $tableRow = $this->_redisInstance->get('autodbdefs.' . $this->_connectionIdent . '.' . $tablename);
            if (is_array($tableRow)) {
                $this->_tableDefs[$tablename] = $tableRow;
                return $this->_tableDefs[$tablename];
            }
        }
        
        // worst case: we fell back to getting the show create table
        $this->_tableDefs[$tablename] = $this->_makeTableDefRow($tablename);
        if ($this->_redisInstance instanceof Redis) {
            $tableRow = $this->_redisInstance->set('autodbdefs.' . $this->_connectionIdent . '.' . $tablename, 
                $this->_tableDefs[$tablename],
                $this->redisTimeout);
        }
        return $this->_tableDefs[$tablename];
    }
    
    /**
     * TODO
     */
    private function _makeTableDefRow($table)
    {
        $ret = array();
        
        if ($this->_sqlResource instanceof mysqli) {
            $query = "Describe " . $this->_sqlResource->real_escape_string($table);

            if ($result = $this->_sqlResource->query($query)) {
                while ($row = $result->fetch_assoc()) {
                    $ret[$row['Field']] = array();
                    $ret[$row['Field']]['type'] = $row['Type'];
                    $ret[$row['Field']]['nullable'] = (bool)($row['Null'] == 'YES');
                    $ret[$row['Field']]['default'] = $row['Default'];
                    if (@$row['Key'] === 'PRI') {
                        if (isset($ret['__primarykey']) || !strstr($row['Type'], 'int') || !strstr($row['Extra'], 'auto_increment')) {
                            throw new AutoDbException("AutoDB: Supported table definitions has exactly one auto_increment integer primary key");
                        }
                        $ret['__primarykey'] = $row['Field'];
                    }
                }
            } else {
                throw new AutoDbException("AutoDB: MYSQL cannot download table definition");
            }
        }
        
        if (static::isPgsqlResource($this->_sqlResource)) {
            $query = "SELECT * FROM INFORMATION_SCHEMA.COLUMNS where table_name = '" . pg_escape_string($table) . "';";
            
            $res = pg_query($this->_sqlResource, $query);
            
            if ($res) {
                while ($row = pg_fetch_assoc($res)) {
                    $ret[$row['column_name']] = array();
                    $ret[$row['column_name']]['type'] = $row['data_type'];
                    $ret[$row['column_name']]['nullable'] = (bool)($row['is_nullable'] == 'YES');
                    $default = null;
                    
                    // this MONSTER is needed to sort DEFAULT and PRIMARY KEY
                    if (substr($row['column_default'], 0, 8) != 'nextval(') { // autoincrement is not processed here
                        if (strpos($row['column_default'], '::text') !== false) {
                            $default = str_replace('::text', '', $row['column_default']); // 'some_default_value' (WITH TICKS ADDED)
                        }
                        if (strpos($row['column_default'], '::json') !== false) {
                            $default = str_replace('::json', '', $row['column_default']); // 'some_default_value' (WITH TICKS ADDED)
                        }                        
                        if (strpos($row['column_default'], '::') === false) {
                            $default = $row['column_default']; // for example number
                        }
                    } else {
                        if (isset($ret['__primarykey'])) {
                            throw new AutoDbException("AutoDB: PGSQL - no support for two nextval sequences");
                        }
                        // highly likely we are primary key, but double check:
                        
                        $priQuery = "SELECT a.attname, format_type(a.atttypid, a.atttypmod) AS data_type
                                        FROM   pg_index i
                                        JOIN   pg_attribute a ON a.attrelid = i.indrelid
                                                             AND a.attnum = ANY(i.indkey)
                                        WHERE  i.indrelid = '" . pg_escape_string($table) . "'::regclass
                                        AND    i.indisprimary;
                        ";
                        
                        $resPri = pg_query($this->_sqlResource, $priQuery);
                        while ($priRow = pg_fetch_assoc($resPri)) {
                            if ($priRow['attname'] != $row['column_name'] || !strstr($row['data_type'], 'int') ) {
                                throw new AutoDbException("AutoDB: PGSQL - no support for more primary keys, non-integer primary keys and nextval on non-primary key");
                            }
                            $ret['__primarykey'] = $row['column_name'];
                        }
                        
                    }
                    // MONSTER END
                    
                    $ret[$row['column_name']]['default'] = $default;
                }
            } else {
                throw new AutoDbException("AutoDB: PGSQL cannot download table definition");
            }
            
        }
        
        if (!isset($ret['__primarykey'])) {
            throw new AutoDbException("AutoDB: no auto_increment primary key was found");
        }
        
        return $ret;
    }
    
    // mysql specific: replace mysqli connection
    public function replaceMysqliResource(mysqli $mysqli)
    {
        if (!$this->_sqlResource instanceof mysqli) {
            throw new AutoDbException('AutoDB: not mysqli resource trioed to be reconnected with mysqli');
        }
        $this->_sqlResource = $mysqli;
        $this->_sqlResReplacing = true;
        foreach ($this->_recordInstances as $recordsArr) {
            foreach ($recordsArr as $record) {
                $record->_replaceMysqli($mysqli);
            }
        }
        $this->_sqlResReplacing = false;
    }
    
    public function replacePgSqlResource($pgSqlRes)
    {
        if (!static::isPgsqlResource($pgSqlRes)) {
            throw new AutoDbException('AutoDB: not pgsql resource trioed to be reconnected with pgsql');
        }
        $this->_sqlResource = $pgSqlRes;
        $this->_sqlResReplacing = true;
        foreach ($this->_recordInstances as $recordsArr) {
            foreach ($recordsArr as $record) {
                $record->_replacePgSql($pgSqlRes);
            }
        }
        $this->_sqlResReplacing = false;        
        
    }
    
    public static function isPgsqlResource($resource)
    {
        return (bool)(is_resource($resource) && get_resource_type($resource) == 'pgsql link');
    }
    
    public function __destruct()
    {
        if ($this->_onDestructDisconnect) {
            if ($this->_sqlResource instanceof mysqli) {
                $this->_sqlResource->disconnect();
            }
            if (static::isPgsqlResource($this->_sqlResource)) {
                pg_close($this->_sqlResource);
            }
        }
    }    
    
}

