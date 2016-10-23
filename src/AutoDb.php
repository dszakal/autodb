<?php

namespace AutoDb;
use mysqli;
use Exception;

class AutoDb {
    
    private $_tableDefs = array();
    private $_redisInstance;
    private $_sqlResource;
    
    private $_recordInstances = array(); // only one reference should exist for all primary key
    
    protected function __construct($sqlResource, $redisInstance = null) {
        $this->_sqlResource = $sqlResource;
        $this->_redisInstance = $redisInstance;
    }
        
    public static function init($sqlResource, $redisInstance = null) {
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
    
    public function getTableDef() {
        
    }
    
}

