<?php

namespace AutoDb;
use mysqli;
use Exception;

class AutoRecord {
    
    private $_tableName;
    private $_attributes = array();
    private $_columnRules = array();
    private $_sqlResource;
    
    protected function __construct() {
        
    }
    
    public static function loadRow(AutoDb $AutoDb, $table, $keyname, $value) {
        
    }
   
    /**
     * 
     * @param \AutoDb\AutoDb $AutoDb
     * @param string $table
     * @param string $where
     * @param int $limit
     * @param int $page
     * @return array
     */
    public static function loadRowsWhere(AutoDb $AutoDb, $table, $where, $limit = -1, $page = 1) {
        
    }
    
    public function attr($column, $setVaule = null) {
        if ($setVaule === null) { // getter mode
            if (isset($this->_attributes[$column]) && isset($this->_columnRules[$column])) {
                return $this->_attributes[$column];
            }
            throw new Exception("AutoDB/AutoRecord Not existing attribute called for $this->_tableName : $column");
        }
        if (isset($this->_attributes[$column]) && isset($this->_columnRules[$column])) {
            // todo setter 
            $value = $this->escape($setVaule);
            $this->_attributes[$column] = $value;
            return $value; // for consistency return with the escaped value
        }
        throw new Exception("AutoDB/AutoRecord Not existing attribute called for $this->_tableName : $column");
    }
    
    public function validate($columnName, $value) {
        return true; // not implemented yet, throws an exception later anyway, just put here to include a lib easier later
    }
    
    public function escape($value) {
        if ($this->_sqlResource instanceof mysqli) {
            return mysqli_real_escape_string($value);
        }
        throw new Exception("AutoDB/AutoRecord: mnot implemented SQL type");
    }
    
}