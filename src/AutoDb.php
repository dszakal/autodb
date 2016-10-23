<?php

namespace AutoDb;
use mysqli;
use Exception;
use Redis;

class AutoDb {
    
    /**
     *
     * @var array - example $this->_tableDefs[$tablename][$column1]['primary_key']
     */
    private $_tableDefs = array();
    
    private $_redisInstance;
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
    
    public function row($table, $keyname, $value) {
        AutoRecord::loadRow($this, $table, $keyname, $value);
    }
    
    public function rowsArray($table, $where, $limit = -1, $page = 1) {
        AutoRecord::loadRows($this, $table, $where, $limit, $page);
    }
    
    public function getTableDef($tablename) {
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
        $this->_tableDefs[$tablename] = $this->_makeTableDefRow();
        if ($this->_redisInstance instanceof Redis) {
            $tableRow = $this->_redisInstance->set('autodbdefs.' . $this->_connectionIdent . '.' . $tablename, 
                $this->_tableDefs[$tablename],
                $this->redisTimeout);
        }
        return $this->_tableDefs[$tablename];
    }
    
    /**
     * todo
     */
    private function _makeTableDefRow()
    {
        $ret = array();
        
        
        
        return $ret;
    }
    
}

