<?php

namespace AutoDb;
use mysqli;
use Exception;
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
        
    public static function init($sqlResource, $redisInstance = null, $connectionIdent = 'default') {
        if (!($sqlResource instanceof mysqli)) {
            throw new Exception('AutoDB/AutoRecord: Only MySQL functionality is implemented yet');
        }
        return new AutoDb($sqlResource, $redisInstance);
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
    public final function rowsArray($table, $where, $limit = 100, $page = 1) 
    {
        return AutoRecord::loadRows($this, $table, $where, $limit, $page);
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
                    $ret[$row['Field']]['nullable'] = $row['Null'];
                    if (@$row['Key'] === 'PRI') {
                        if (isset($ret['__primarykey']) || !strstr($row['Type'], 'int') || !strstr($row['Extra'], 'auto_increment')) {
                            throw new Exception("AutoDB: Supported table definitions has exactly one auto_increment integer primary key");
                        }
                        $ret['__primarykey'] = $row['Field'];
                    }
                }
            } else {
                throw new Exception("AutoDB: cannot download table definition");
            }
        }
        
        return $ret;
    }
    
}

