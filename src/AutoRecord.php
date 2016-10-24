<?php

namespace AutoDb;
use mysqli;
use Exception;

class AutoRecord {
    
    private $_tableName;
    private $_attributes = array();
    
    /**
     *
     * @var array - ['column']['column_properties'] - TODO
     */
    private $_columnRules = array();
    
    private $_rowChanged = array();
    
    /**
     *
     * @var type AutoDb
     */
    private $_autoDb;
    
    /** 
     *
     * @var mysqli
     */
    private $_sqlResource;
    
    private $_primaryKey;
    
    public function getTableName() {
        return $this->_tableName;
    }

    public function getAttributes() {
        return $this->_attributes;
    }

    public function getColumnRules() {
        return $this->_columnRules;
    }

    public function getRowChanged() {
        return $this->_rowChanged;
    }

    public function getSqlResource() {
        return $this->_sqlResource;
    }

    public function getPrimaryKey() {
        return $this->_primaryKey;
    }

        
    protected function __construct(AutoDb $autoDb, $table, $columnRules, $sqlResource) 
    {
        $this->_tableName = $table;
        $this->_columnRules = $columnRules;
        $this->_sqlResource = $sqlResource;
        $this->_primaryKey = $columnRules['__primarykey'];
    }
    
    private function initAttrsEmpty() {
        foreach ($this->_columnRules as $key => $value) {
            $this->_attributes[$key] = null;
        }
    }
    
    public static function loadRow(AutoDb $autoDb, $table, $keyname = null, $value = null) 
    {
        $columnRules = $autoDb->getTableDef($table);
        $record = new static($autoDb, $table, $columnRules, $autoDb->getSqlResource());
        // new row
        if (is_null($value)) {
            $record->initAttrsEmpty();
            return $record;
        }
        
        // existing row in object cache (ensuring same reference
        if (isset($autoDb->getRecordInstances()[$this->_primaryKey][$value])) {
            return $autoDb->getRecordInstances()[$this->_primaryKey][$value];
        }
        
        // load object from database query:
        
        
        $autoDb->_addInstance($record); // so it will return next time the same reference
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
    public static function loadRowsWhere(AutoDb $autoDb, $table, $where, $limit = 100, $page = 1) 
    {
        $columnRules = $autoDb->getTableDef($table);
        $ret = array();
        
        // todo - new instances with one query
        // $record = new static($table, $columnRules, $autoDb->getSqlResource());
        
        return $ret;
    }
    
    public function attr($column, $setValue = null) {
        // getter mode
        if (func_num_args() == 1) {
            if (array_key_exists($column, $this->_attributes) && array_key_exists($column, $this->_columnRules)) {
                return $this->_attributes[$column];
            }
            throw new Exception("AutoDB/AutoRecord Not existing attribute called for $this->_tableName : $column");
        }
        
        //setter mode
        if (array_key_exists($column, $this->_attributes) && array_key_exists($column, $this->_columnRules)) {
            if (!$this->validate($this->_attributes[$column], $setValue)) {
                 throw new Exception("AutoDB/AutoRecord Invalid Data attribute added for $this->_tableName : $column : $setValue");
            }
            if ($this->_attributes[$column] !== $setValue) {
                $this->_rowChanged[] = $column;
            }
            $this->_attributes[$column] = $setValue;
            return $setValue; // for consistency return with the escaped value
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
    
    public function getPrimaryKeyValue()
    {
        return $this->attr($this->_primaryKey);
    }
    
    /**
     * Save - final - only saves single row
     */
    public final function save()
    {
        if ($this->getPrimaryKeyValue() < 1) {
            // new row, insert
            if ($this->_sqlResource instanceof mysqli) {
                $sql = 'INSERT INTO ';
                
                // todo inserts and values
                
                if (!$this->_sqlResource->query($sql)) {
                    throw new Exception("AutoDb/Autorecord: error inserting new record: " . $sql);
                }
                return;
            }
            
            throw new Exception("AutoDb/Autorecord: wrong sql resource");
        }
        
        // Existing record, update
        if (empty($this->_rowChanged)) {
            return; // nothing to do, nothing changed
        }
        
        if ($this->_sqlResource instanceof mysqli) {
            $sql = 'UPDATE SET ';

            foreach ($this->_rowChanged as $row) {
                // todo rows and values
            }

            $sql .= " WHERE $this->_primaryKey = " . (int)$this->attr($this->_primaryKey);
            if (!$this->_sqlResource->query($sql)) {
                throw new Exception("AutoDb/Autorecord: error inserting new record: " . $sql . " " . $this->_sqlResource->error);
            }
            $this->_attributes[$this->getPrimaryKey()] = $this->_sqlResource->insert_id;
            $this->_autoDb->_addInstance($this);
            return;
        }
    }
    
    // Todo: multi saveMore (static, on array)
    
}