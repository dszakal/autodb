<?php

namespace AutoDb;
use mysqli;
use AutoDb\AutoDbException;
use mysqli_result;

class AutoRecord {
    
    const DEAD_REFERENCE_PK_VALUE = -1;
    
    private $_tableName;
    private $_attributes = array();
    
    /**
     *
     * @var array - ['column']['column_properties']
     */
    private $_columnRules = array();
    
    private $_rowChanged = array();
    private $_originals = array();
    
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
        $this->_autoDb = $autoDb;
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
    
    private function initAttrsFromQueryRow(array $row)
    {
        foreach ($row as $key => $value) {
            $this->_attributes[$key] = $value;
        }
    }
    
    public static final function loadRow(AutoDb $autoDb, $table, $keyname = null, $value = null) 
    {
        if (isset($autoDb->getBannedTables()[$table])) {
            throw new AutoDbException("AutoDb/Autorecord: this table is blocked to be used with autorecord");
        }
        
        $columnRules = $autoDb->getTableDef($table);
        $sqlr = $autoDb->getSqlResource();
        $record = new static($autoDb, $table, $columnRules, $sqlr);
        // new row
        if (is_null($value)) {
            $record->initAttrsEmpty();
            return $record;
        }
        
        // existing row in object cache (ensuring same reference
        if (isset($autoDb->getRecordInstances()[$table][$value])) {
            return $autoDb->getRecordInstances()[$table][$value];
        }
        
        // load object from database query:
        $sqlGet = "SELECT * FROM " . $sqlr->real_escape_string($table) . 
            " WHERE " . $sqlr->real_escape_string($keyname) . " = " . (int)$value;
        
        $result = $sqlr->query($sqlGet);
        $row = array();
        if ($result) {
            $row = $result->fetch_assoc();
        }
        if (!empty($row)) {
            $record->initAttrsFromQueryRow($row);
        } else {
            throw new AutoDbException("AutoDb/Autorecord: error loading record with PKey: " . $sqlGet . " " . $sqlr->error);
        }
        
        $autoDb->_addInstance($record); // so it will return next time the same reference
        return $record;
    }
   
    /**
     * 
     * @param \AutoDb\AutoDb $autoDb
     * @param string $table
     * @param string $where - BEWARE: UNESCAPED
     * @param int $limit
     * @param int $page
     * @return array
     */
    public static final function loadRowsWhere(AutoDb $autoDb, $table, $where, $limit = -1, $page = 1) 
    {
        if (isset($autoDb->getBannedTables()[$table])) {
            throw new AutoDbException("AutoDb/Autorecord: this table is blocked to be used with autorecord");
        }
        $columnRules = $autoDb->getTableDef($table);
        $sqlr = $autoDb->getSqlResource();
        $ret = array();
        
        $sqlGet = "SELECT * FROM " . $sqlr->real_escape_string($table) . 
            ' WHERE ' . $where;
        
        if ($limit > 0) {
            $sqlGet .= ' LIMIT ' . (int)($limit * ($page-1)) . ', ' . (int)$limit;
        }
        
        $result = $sqlr->query($sqlGet);
        if ($result instanceof mysqli_result) {
            while ($row = $result->fetch_assoc()) {
                // get from cache if already existing to keep only one instance alive
                if (isset($autoDb->getRecordInstances()[$table][$row[$columnRules['__primarykey']]])) {
                    $record = $autoDb->getRecordInstances()[$table][$row[$columnRules['__primarykey']]];
                    $record->initAttrsFromQueryRow($row);
                } else {
                    $record = new static($autoDb, $table, $columnRules, $sqlr);
                    $record->initAttrsFromQueryRow($row);
                    $autoDb->_addInstance($record);
                }
                $ret[] = $record;
            }
        }
        
        return $ret;
    }
    
    /**
     * 
     * @param string $column - the column to read/write
     * @param mixed $setValue - optional - one param version is getter, with this param set it's setter
     * @return mixed - the attribute - the one to be saved if you already changed it
     * @throws AutoDbException
     */
    public function attr($column, $setValue = null) {
        if ($this->isDeadReference()) {
            throw new AutoDbException("AutoDb/Autorecord: Trying to work with dead reference");
        }
        // getter mode
        if (func_num_args() == 1) {
            if (array_key_exists($column, $this->_attributes) && array_key_exists($column, $this->_columnRules)) {
                return $this->_attributes[$column];
            }
            throw new AutoDbException("AutoDB/AutoRecord Not existing attribute called for $this->_tableName : $column");
        }
        
        //setter mode
        if (array_key_exists($column, $this->_attributes) && array_key_exists($column, $this->_columnRules)) {
            if (!$this->validate($this->_attributes[$column], $setValue)) {
                 throw new AutoDbException("AutoDB/AutoRecord Invalid Data attribute added for $this->_tableName : $column : $setValue");
            }
            if ($this->_attributes[$column] !== $setValue) {
                if (!in_array($column, $this->_rowChanged)) {
                    $this->_rowChanged[] = $column;
                }
                
                $this->_originals[$column] = $this->_attributes[$column];
            }
            $this->_attributes[$column] = $setValue;
            return $setValue; // for consistency return with the escaped value
        }
        throw new AutoDbException("AutoDB/AutoRecord Not existing attribute called for $this->_tableName : $column");
    }
    
    /**
     * 
     * @param string $column
     * @return mixed - the attribute - the one before save if you already changed it
     * @throws AutoDbException
     */
    public function dbAttr($column)
    {
        if ($this->isDeadReference()) {
            throw new AutoDbException("AutoDb/Autorecord: Trying to work with dead reference");
        }
        if (isset($this->_originals[$column])) {
            return $this->_originals[$column];
        }
        return $this->attr($column);
    }
    
    /**
     * 
     * @param string $column
     * @return mixed - the attribute - the one before save if you already changed it, read forced from db
     * @throws AutoDbException
     */    
    public function dbAttrForce($column)
    {
        if ($this->isDeadReference()) {
            throw new AutoDbException("AutoDb/Autorecord: Trying to work with dead reference");
        }
        $sqlr = $this->_sqlResource;
        if ($sqlr instanceof mysqli) {
            $sql = 'SELECT ' . $sqlr->real_escape_string($column) . ' FROM ' . $sqlr->real_escape_string($this->_tableName) .
            ' WHERE ' . $this->getPrimaryKey() . ' = ' . (int)$this->getPrimaryKeyValue();
            $result = $sqlr->query($sql);
            $row = $result->fetch_assoc();
            return $row[$column];
        }
        throw new AutoDbException ("Autodb/autorecord - Forced column reader: wrong SQL resource instance or non existing row anymore");
    }
    
    public function validate($columnName, $value) {
        return true; // not implemented yet, throws an exception later anyway, just put here to include a lib easier later
    }
    
    public function escape($value) {
        if ($this->_sqlResource instanceof mysqli) {
            return mysqli_real_escape_string($value);
        }
        throw new AutoDbException("AutoDB/AutoRecord: mnot implemented SQL type");
    }
    
    public function getPrimaryKeyValue()
    {
        return $this->attr($this->_primaryKey);
    }
    
    private function _getCommasAndEscapes($type, $value) {
        if ($this->_sqlResource instanceof mysqli) {
            $sqlr = $this->_sqlResource;
            if (strstr($type, 'int')) {
                return (int)$value;
            }
            if (strstr($type, 'dec')) {
                throw new AutoDbException("AutoDb/Autorecord: decimal safe escape not implemented yet :(");
            }
            if (strstr($type, 'float') || strstr($type, 'double' || strstr($type, 'real'))) {
                return (double)$value;
            }
            if (strstr($type, 'text') || strstr($type, 'char') || strstr($type, 'date') || strstr($type, 'time')) {
                if (is_null($value)) {
                    return 'NULL';
                }
                if (strstr($type, 'date') || strstr($type, 'time')) {
                    if ($value === 'NOW()') {
                        return 'NOW()';
                    }
                }
                return "'" . $sqlr->real_escape_string($value) . "'";
            }
        }
    }
    
    /**
     * Reloads all the attributes to match the database 
     * @throws AutoDbException
     */
    public final function forceReloadAttributes()
    {
        if ($this->isDeadReference()) {
            throw new AutoDbException("AutoDb/Autorecord: Trying to reload attributes on dead reference");
        }
        if ($this->getPrimaryKeyValue() < 1) {
            throw new AutoDbException("AutoDb/Autorecord: Trying to reload attributes on unsaved row");
        }        
        $sqlr = $this->_sqlResource;
        $sqlGet = "SELECT * FROM " . $sqlr->real_escape_string($this->getTableName()) . 
            " WHERE " . $sqlr->real_escape_string($this->getPrimaryKey()) . " = " . (int)$this->getPrimaryKeyValue();
        
        $result = $sqlr->query($sqlGet);
        $row = array();
        if ($result) {
            $row = $result->fetch_assoc();
        }
        if (!empty($row)) {
            $this->initAttrsFromQueryRow($row);
        } else {
            throw new AutoDbException("AutoDb/Autorecord: error loading record with PKey: " . $sqlGet . " " . $sqlr->error);
        }
        $this->_rowChanged = array();
        $this->_originals = array();
    }
    
    /**
     * Save - final - only saves single row
     * DOES NOT RELOAD ATTRIBUTES WHICH IS AN EXTRA SELECT use $this->forceReloadAttributes()
     * For concurrent saves see saveMore()
     * 
     * @return integer
     * @throws AutoDbException - in case of any error
     */
    public final function save()
    {
        if (array_key_exists($this->_tableName, $this->_autoDb->getReadOnlyTables())) {
            throw new AutoDbException("AutoDb/Autorecord: this table is read only, save is forbidden");
        }
        if ($this->isDeadReference()) {
            throw new AutoDbException("AutoDb/Autorecord: Trying to save dead reference");
        }
        if ($this->getPrimaryKeyValue() < 1) {
            // new row, insert
            if ($this->_sqlResource instanceof mysqli) {
                $sqlr = $this->_sqlResource;
                $sql = 'INSERT INTO ' . $sqlr->real_escape_string($this->getTableName()). ' ';
                
                $colNames = '';
                $values = '';
                
                $comma = false;
                foreach ($this->_rowChanged as $row) {
                    if (!$comma) {
                        $comma = true;
                    } else {
                        $colNames .= ',';
                        $values .= ',';
                    }
                    $colNames .= '`' . $sqlr->real_escape_string($row) . '`';
                    $values .= $this->_getCommasAndEscapes($this->_columnRules[$row]['type'], $this->_attributes[$row]);
                }
                
                $sql .= "( $colNames ) VALUES ( $values )";
                
                if (!$this->_sqlResource->query($sql)) {
                    throw new AutoDbException("AutoDb/Autorecord: error inserting new record: " . $sql . " " . $this->_sqlResource->error);
                }
                $this->_attributes[$this->getPrimaryKey()] = $this->_sqlResource->insert_id;
                $this->_autoDb->_addInstance($this); // add new object to pool
                $this->_rowChanged = array();
                $this->_originals = array();
                return $sqlr->affected_rows;
            }
            
            throw new AutoDbException("AutoDb/Autorecord: wrong sql resource");
        }
        
        // Existing record, update
        if (array_key_exists($this->_tableName, $this->_autoDb->getWriteOnceTables())) {
            throw new AutoDbException("AutoDb/Autorecord: this table is write-once, update is forbidden");
        }
        if (empty($this->_rowChanged)) {
            return 0; // nothing to do, nothing changed
        }
        
        if ($this->_sqlResource instanceof mysqli) {
            $sqlr = $this->_sqlResource;
            $sql = 'UPDATE ' . $sqlr->real_escape_string($this->getTableName()) . ' SET ';

            $comma = false;
            foreach ($this->_rowChanged as $row) {
                if (!$comma) {
                    $comma = true;
                } else {
                    $sql .= ',';
                }
                $sql .= ' ' . $sqlr->real_escape_string($row) . ' = ' . 
                    $this->_getCommasAndEscapes($this->_columnRules[$row]['type'], $this->_attributes[$row]);
            }

            $sql .= " WHERE $this->_primaryKey = " . (int)$this->attr($this->_primaryKey);
            if (!$this->_sqlResource->query($sql)) {
                throw new AutoDbException("AutoDb/Autorecord: error inserting new record: " . $sql . " " . $this->_sqlResource->error);
            }
            $this->_rowChanged = array();
            $this->_originals = array();
            return $sqlr->affected_rows;
        }
        throw new AutoDbException("AutoDb/Autorecord: unknown error when saving"); // never happens
    }
    
    public final function delete()
    {
        if ($this->_sqlResource instanceof mysqli) {
            $sql = 'DELETE FROM ' . $this->_tableName . ' WHERE ' . $this->_primaryKey . ' = ' 
                . (int)$this->getPrimaryKeyValue();
            if (!$this->_sqlResource->query($sql)) {
                throw new AutoDbException('AutoDb/Autorecord: Error deleting row');
            }
            
            $this->setDeadReference();
            return;
        }
        throw new AutoDbException('AutoDb/Autorecord: Unknown error');
    }
    
    public function setDeadReference()
    {
        $primaryKeyWas = $this->getPrimaryKeyValue();
        $this->attr($this->_primaryKey, self::DEAD_REFERENCE_PK_VALUE);
        $this->_attributes = array();
        $this->_originals = array();
        $this->_autoDb->_removeKey($this->_tableName, $primaryKeyWas);
    }
    
    public function isDeadReference()
    {
        return (bool)($this->_primaryKey === self::DEAD_REFERENCE_PK_VALUE);
    }
    
    /**
     * Saves more rows optimised, but inserted rows' reference dropped(!), as one query runs for insert
     * @param array $arrayOfAutoRecords - same AutoDb, same Connection, same TABLE, no other instances in the array
     * @param $insertCommand - INSERT INTO or REPLACE INTO or INSERT IGNORE INTO - for concurrent writes
     * @param $suffix - for example ' ON DUPLICATE KEY UPDATE last_saved = NOW() '
     * @return integer - updated rows
     * @throws AutoDbException
     */
    public static final function saveMore(array $arrayOfAutoRecords, $insertCommand = 'INSERT INTO', $suffix = '')
    {
        if (empty($arrayOfAutoRecords)) {
            return 0;
        }
        $rowCount = 0;
        $tablename = '';
        $toUpdate = array();
        $toInsert = array();
        $autoDb = null;
        $sqlr = null;
        foreach ($arrayOfAutoRecords as $autoRecord) {
            if (!($autoRecord instanceof AutoRecord)) {
                throw new AutoDbException('AutoDb/Autorecord: saveMore() should get an array of AutoRecord instances (also from same table)');
            }
            if ($tablename === '') {
                $tablename = $autoRecord->getTableName();
            }
            if ($tablename !== $autoRecord->getTableName()) {
                throw new AutoDbException('AutoDb/Autorecord: saveMore() should get an array of AutoRecord instances from same table');
            }
            if (is_null($autoDb)) {
                $autoDb = $autoRecord->_autoDb;
                $sqlr = $autoDb->getSqlResource();
            } else {
                if ($autoRecord->_autoDb !== $autoDb || $sqlr !== $autoRecord->getSqlResource()) {
                    throw new AutoDbException('AutoDb/Autorecord: This was a very dangerous call to the method saveMore(), aborting');
                }
            }
            
            
            if ($autoRecord->getPrimaryKeyValue() > 0) {
                $toUpdate[] = $autoRecord;
            } else {
                $toInsert[] = $autoRecord;
            }
        }
        
        foreach ($toUpdate as $autoRecord) {
            $autoRecord->save(); // cannot be more optimal
            ++$rowCount;
        }
        
        if (empty($toInsert)) {
            return $rowCount;
        }
        
        // INSERT optimised, and return total updated rows
        return $rowCount + self::_saveCheckedArrayOptimised($toInsert, $sqlr, $insertCommand, $suffix);
    }
    
    /**
     * DO NOT USE, helper for saveMore() inserts, making easier to read
     * @param array $toInsert
     * @param mixed $sqlr
     * @return integer - count of updated rows
     * @throws AutoDbException
     */
    private static final function _saveCheckedArrayOptimised(array $toInsert, $sqlr, $insertCommand, $suffix)
    {
        $insertQuery = '';
        $columns = array(); // to make sure attributes are in order
        $counter = 0;
        foreach ($toInsert as $autoRecord) { 
            if ($sqlr instanceof mysqli) {
                // set columns if first run
                if ($insertQuery === '') {
                    $insertQuery = $insertCommand . ' ' . $sqlr->real_escape_string($autoRecord->getTableName()) . ' ';
                    
                    $colNames = '';

                    $comma = false;
                    foreach ($autoRecord->_columnRules as $key => $rules) {
                        if ($key === '__primarykey') { // not real column
                            continue;
                        }
                        $columns[] = $key;
                        if (!$comma) {
                            $comma = true;
                        } else {
                            $colNames .= ',';
                        }
                        $colNames .= '`' . $sqlr->real_escape_string($key) . '`';
                    }
                    $insertQuery .= "( $colNames ) VALUES ";
                }
                
                //add values
                
            }
            
            if ($counter++ >= 1) {
                $insertQuery .= ','; // new row
            }
            
            $insertQuery .= ' ( ';
            foreach ($columns as $key => $col) {
                if ($key > 0) {
                    $insertQuery .= ' , ';
                }
                $insertQuery .= $autoRecord->_getCommasAndEscapes($autoRecord->_columnRules[$col]['type'], $autoRecord->_attributes[$col]);
            }
            $insertQuery .= ' ) ';
            
            // we don't know insert ID's, all references are dead :(
            $autoRecord->setDeadReference();
        }
        
        $insertQuery .= $suffix; // 'ON DUPLIACTE KEY UPDATE ... '
        
        if ($sqlr instanceof mysqli) {
            if (!$sqlr->query($insertQuery)) {
                throw new AutoDbException("AutoDb/Autorecord: saveMore(): error inserting new records: " . $insertQuery . " " . $sqlr->error);
            }
            return $sqlr->affected_rows;
        }
        return 0;
    }
    
    /**
     * 
     * @param array $arrayOfAutoRecords - same AutoDb, same Connection, same TABLE, no other instances in the array
     * @return integer - deleted rows
     * @throws AutoDbException
     */
    public static final function deleteMore(array $arrayOfAutoRecords)
    {
        if (empty($arrayOfAutoRecords)) {
            return 0;
        }
        $tablename = '';
        $autoDb = null;
        $sqlr = null;
        $primaryKey = null;
        $toDelete = array();
        foreach ($arrayOfAutoRecords as $autoRecord) {
            if (!($autoRecord instanceof AutoRecord)) {
                throw new AutoDbException('AutoDb/Autorecord: deleteMore() should get an array of AutoRecord instances (also from same table)');
            }
            if ($tablename === '') {
                $tablename = $autoRecord->getTableName();
                $primaryKey = $autoRecord->getPrimaryKey();
            }
            if ($tablename !== $autoRecord->getTableName()) {
                throw new AutoDbException('AutoDb/Autorecord: deleteMore() should get an array of AutoRecord instances from same table');
            }
            if (is_null($autoDb)) {
                $autoDb = $autoRecord->_autoDb;
                $sqlr = $autoDb->getSqlResource();
            } else {
                if ($autoRecord->_autoDb !== $autoDb || $sqlr !== $autoRecord->getSqlResource()) {
                    throw new AutoDbException('AutoDb/Autorecord: This was a very dangerous call to the method deleteMore(), aborting');
                }
            }
            
            
            if ($autoRecord->getPrimaryKeyValue() > 0) {
                $toDelete[] = $autoRecord;
            }
        }
        if (empty($toDelete)) {
            return 0;
        }
        
        $deleteIds = array();
        foreach ($toDelete as $autoRecord) {
            $deleteIds[] = (int)$autoRecord->getPrimaryKeyValue();
            $autoRecord->setDeadReference();
        }
        
        if ($sqlr instanceof mysqli) {
            $deleteQuery = 'DELETE FROM ' . $sqlr->real_escape_string($tablename) . ' WHERE '
                . $sqlr->real_escape_string($primaryKey) . ' IN (' . implode(',', $deleteIds) . ')';

            if (!$sqlr->query($deleteQuery)) {
                throw new AutoDbException('AutoDb/Autorecord: deleteMore() failed executing query ' . $deleteQuery . " " . $sqlr->error);
            }
            return $sqlr->affected_rows;
        }
        throw new AutoDbException('AutoDb/Autorecord: deleteMore() - unknown error');
    }
    
}