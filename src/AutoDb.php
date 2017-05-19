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
    private $_mysqliReplacing = false;
    
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
        if (!($sqlResource instanceof mysqli)) {
            throw new AutoDbException('AutoDB/AutoRecord: Only MySQL functionality is implemented yet');
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
    
    public function getMysqliReplacing() {
        return $this->_mysqliReplacing;
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
                throw new AutoDbException("AutoDB: cannot download table definition");
            }
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
        $this->_mysqliReplacing = true;
        foreach ($this->_recordInstances as $recordsArr) {
            foreach ($recordsArr as $record) {
                $record->_replaceMysqli($mysqli);
            }
        }
        $this->_mysqliReplacing = false;
    }
    
}

