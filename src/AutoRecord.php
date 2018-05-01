<?php

namespace AutoDb;
use mysqli;
use Exception;
use AutoDb\AutoDbException;
use AutoDb\AutoDb;
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
    
    private $state;
    
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
    
    public function getState() {
        return $this->state;
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
        $this->state = 'new';
    }
    
    private function initAttrsFromQueryRow(array $row)
    {
        foreach ($row as $key => $value) {
            $this->_attributes[$key] = $value;
        }
        $this->state = 'synced';
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
        
        if ($sqlr instanceof mysqli) {
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
        }
        
        if (AutoDb::isPgsqlResource($sqlr)) {
            $sqlGet = "SELECT * FROM " . pg_escape_string($table) . 
                " WHERE " . pg_escape_string($keyname) . " = " . (int)$value; 
            
            $result = pg_query($sqlr, $sqlGet);
            
            if ($result) {
                $row = pg_fetch_assoc($result);
            }
            if (!empty($row)) {
                $record->initAttrsFromQueryRow($row);
            } else {
                throw new AutoDbException("AutoDb/Autorecord: error loading record with PKey: " . $sqlGet . " " .  pg_last_error($sqlr));
            }            
            
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
        
        if ($sqlr instanceof mysqli) {
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
            } else {
                throw new AutoDbException("AutoDb/Autorecord: mysql error while loading more: " . $sqlr->error);
            }
        }
        
        if (AutoDb::isPgsqlResource($sqlr)) {
            $sqlGet = "SELECT * FROM " . pg_escape_string($table) . 
                ' WHERE ' . $where;
            
            if ($limit > 0) {
                $sqlGet .= ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)($limit * ($page-1));
            }

            $result = pg_query($sqlr, $sqlGet);
            if (is_resource($result)) {
                while ($row = pg_fetch_assoc($result)) {
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
            } else {
                throw new AutoDbException("AutoDb/Autorecord: postgresql error while loading more: " . pg_last_error($sqlr));
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
            if ($result instanceof mysqli_result) {
                $row = $result->fetch_assoc();
                return $row[$column];
            }
        }
        
        if (AutoDb::isPgsqlResource($sqlr)) {
            $sql = 'SELECT ' . pg_escape_string($column) . ' FROM ' . pg_escape_string($this->_tableName) .
            ' WHERE ' . $this->getPrimaryKey() . ' = ' . (int)$this->getPrimaryKeyValue();
            $result = pg_query($sqlr, $sql);
            if (is_resource($result)) {
                $row = pg_fetch_assoc($result);
                return $row[$column];
            }            
        }
        
        throw new AutoDbException ("Autodb/autorecord - Forced column reader: wrong SQL resource instance or non existing row anymore");
    }
    
    public function validate($columnName, $value) 
    {
        return true; // not implemented yet, throws an exception later anyway, just put here to include a lib easier later
    }
    
    public function escape($value) 
    {
        
        if ($this->_sqlResource instanceof mysqli) {
            return mysqli_real_escape_string($value);
        }
        
        if (AutoDb::isPgsqlResource($this->_sqlResource)) {
            return pg_escape_string($value);
        }
        
        throw new AutoDbException("AutoDB/AutoRecord: not implemented SQL type");
    }
    
    public function getPrimaryKeyValue()
    {
        return $this->attr($this->_primaryKey);
    }
    
    
    
    // VALUES PROCESSING
    
    private function _getCommasAndEscapes($col, $value) 
    {
        $type = $this->_columnRules[$col]['type'];
        $nullable = $this->_columnRules[$col]['nullable'];
        $default = $this->_columnRules[$col]['default'];
        if ($this->_sqlResource instanceof mysqli) {
            return $this->_getCommasAndEscapesMysqli($value, $type, $nullable, $default);
        }
        if (AutoDb::isPgsqlResource($this->_sqlResource)) {
            return $this->_getCommasAndEscapesPostgres($value, $type, $nullable, $default);
        }
    }
    
    private function _getCommasAndEscapesMysqli($value, $type, $nullable, $default) 
    {
        if (is_null($value) && $nullable) {
            return 'NULL';
        }

        // From this point it is not nullable so "null" means "default value". You might not like this, use strict mode then
        if ($this->_autoDb->getStrictNullableMode() && !$nullable && is_null($value)) {
            throw new AutoDbException("AutoDb/Autorecord: NULL should not be used here, the col is not nullable :(");
        }
        $sqlr = $this->_sqlResource;
        if (strstr($type, 'int')) {
            if (is_null($value) && $default != '') {
                return (int)$default;
            }
            return (int)$value;
        }
        if (strstr($type, 'dec')) {
            throw new AutoDbException("AutoDb/Autorecord: decimal safe escape not implemented yet :(");
        }
        if (strstr($type, 'float') || strstr($type, 'double') || strstr($type, 'real')) {
            if (is_null($value) && $default != '') {
                return (double)$default;
            }
            return (double)$value;
        }
        if (strstr($type, 'text') || strstr($type, 'char') || strstr($type, 'date') || strstr($type, 'time')) {
            if (is_null($value) && !is_null($default)) {
                $value = $default; // for quotes later
            }
            if (strstr($type, 'date') || strstr($type, 'time')) {
                if ($value === 'NOW()') {
                    return 'NOW()';
                }
                if ($value === 'CURRENT_TIMESTAMP') {
                    return 'CURRENT_TIMESTAMP';
                }
            }
            return "'" . $sqlr->real_escape_string($value) . "'";
        }        
    }
    
    private function _getCommasAndEscapesPostgres($value, $type, $nullable, $default)
    {
        if (is_null($value) && $nullable) {
            return 'NULL';
        }

        if (strstr($type, 'int')) {
            if (is_null($value) && $default != '') {
                return (int)$default;
            }
            return (int)$value;
        }
        
        if (strstr($type, 'float') || strstr($type, 'double') || strstr($type, 'real')) {
            if (is_null($value) && $default != '') {
                return (double)$default;
            }
            return (double)$value;
        }

        if (strstr($type, 'date') || strstr($type, 'time')) {
            if ($value === 'NOW()') {
                return 'NOW()';
            }
        }
        
        if (strstr($type, 'text') && strlen($value) > 0) {
        	return "'" . $this->escape($value) . "'";
        }
        
        if ($default) {
            return $default; // here it contains the quotes already, :: deleted already
        }
        
        return "'" . $this->escape($value) . "'";
    }
    
    // VALUES PROCESSING END
    
    
    
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
        
        if ($sqlr instanceof mysqli) {
            $sqlGet = "SELECT * FROM " . $sqlr->real_escape_string($this->getTableName()) . 
                " WHERE " . $sqlr->real_escape_string($this->getPrimaryKey()) . " = " . (int)$this->getPrimaryKeyValue();

            $result = $sqlr->query($sqlGet);
            $row = array();
            if ($result) {
                $row = $result->fetch_assoc();
            }
        }
        
        if (AutoDb::isPgsqlResource($sqlr)) {
            $sqlGet = "SELECT * FROM " . pg_escape_string($this->getTableName()) . 
                " WHERE " . pg_escape_string($this->getPrimaryKey()) . " = " . (int)$this->getPrimaryKeyValue();

            $result = pg_query($sqlr, $sqlGet);
            $row = array();
            if ($result) {
                $row = pg_fetch_assoc($result);
            }            
        }
        
        if (!empty($row)) {
            $this->initAttrsFromQueryRow($row);
        } else {
            throw new AutoDbException("AutoDb/Autorecord: error loading record with PKey: " . $sqlGet . " " . $sqlr->error);
        }
        $this->_rowChanged = array();
        $this->_originals = array();
    }
    
    
    
    // Save START
    
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
                $ret = $this->_insertMysqli();
            }
            
            if (AutoDb::isPgsqlResource($this->_sqlResource)) {
                $ret = $this->_insertPgSql();
            }
            
            $this->_autoDb->_addInstance($this); // add new object to pool
            $this->_rowChanged = array();
            $this->_originals = array();
            $this->state = 'saved_not_synced';
            
            return $ret;
        }
        
        // Existing record, update
        if (array_key_exists($this->_tableName, $this->_autoDb->getWriteOnceTables())) {
            throw new AutoDbException("AutoDb/Autorecord: this table is write-once, update is forbidden");
        }
        if (empty($this->_rowChanged)) {
            return 0; // nothing to do, nothing changed
        }
        
        if ($this->_sqlResource instanceof mysqli) {
            return $this->_updateMysqli();
        }
        
        if (AutoDb::isPgsqlResource($this->_sqlResource)) {
            return $this->_updatePgsql();
        }
        
        throw new AutoDbException("AutoDb/Autorecord: unknown error when saving"); // never happens
    }
    
    private function _insertMysqli()
    {
        $sqlr = $this->_sqlResource;
        $sql = 'INSERT INTO `' . $sqlr->real_escape_string($this->getTableName()). '` ';

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
            $values .= $this->_getCommasAndEscapes($row, $this->_attributes[$row]);
        }

        $sql .= "( $colNames ) VALUES ( $values )";

        if (!$this->_sqlResource->query($sql)) {
            throw new AutoDbException("AutoDb/Autorecord: mysqli - error inserting new record: " . $sql . " " . $this->_sqlResource->error);
        }
        $this->_attributes[$this->getPrimaryKey()] = $this->_sqlResource->insert_id;
        return $sqlr->affected_rows;        
    }    
    
    private function _insertPgSql()
    {
        $sqlr = $this->_sqlResource;
        $sql = 'INSERT INTO ' . pg_escape_string($this->getTableName()). ' ';

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
            $colNames .= pg_escape_string($row);
            $values .= $this->_getCommasAndEscapes($row, $this->_attributes[$row]);
        }

        $sql .= "( $colNames ) VALUES ( $values ) RETURNING " . $this->getPrimaryKey();
        try {
            $pgReturn = pg_query($sqlr, $sql);
        } catch (Exception $e) {
            throw new AutoDbException("AutoDb/Autorecord: pgsql - error inserting new record at query: " . $sql . " " . pg_last_error($sqlr) . ' ' . $e->getMessage());
        }
        if (!is_resource($pgReturn)) {
            throw new AutoDbException("AutoDb/Autorecord: pgsql - error inserting new record: " . $sql . " " . pg_last_error($sqlr));
        }
        
        $this->_attributes[$this->getPrimaryKey()] = pg_fetch_assoc($pgReturn)[$this->getPrimaryKey()];

        return pg_affected_rows($pgReturn);         
    }
    
    private function _updateMysqli()
    {
        $sqlr = $this->_sqlResource;
        $sql = 'UPDATE `' . $sqlr->real_escape_string($this->getTableName()) . '` SET ';

        $comma = false;
        foreach ($this->_rowChanged as $row) {
            if (!$comma) {
                $comma = true;
            } else {
                $sql .= ',';
            }
            $sql .= ' ' . $sqlr->real_escape_string($row) . ' = ' . 
                $this->_getCommasAndEscapes($row, $this->_attributes[$row]);
        }

        $sql .= " WHERE $this->_primaryKey = " . (int)$this->attr($this->_primaryKey);
        if (!$this->_sqlResource->query($sql)) {
            throw new AutoDbException("AutoDb/Autorecord: mysqli - error inserting new record: " . $sql . " " . $this->_sqlResource->error);
        }
        $this->_rowChanged = array();
        $this->_originals = array();
        $this->state = 'saved_not_synced';
        return $sqlr->affected_rows;        
    }
    
    private function _updatePgsql()
    {
        $sqlr = $this->_sqlResource;
        $sql = 'UPDATE ' . pg_escape_string($this->getTableName()) . ' SET ';

        $comma = false;
        foreach ($this->_rowChanged as $row) {
            if (!$comma) {
                $comma = true;
            } else {
                $sql .= ',';
            }
            $sql .= ' ' . pg_escape_string($row) . ' = ' . 
                $this->_getCommasAndEscapes($row, $this->_attributes[$row]);
        }

        $sql .= " WHERE $this->_primaryKey = " . (int)$this->attr($this->_primaryKey);
        $pgResult = pg_query($sqlr, $sql);
        if (!$pgResult) {
            throw new AutoDbException("AutoDb/Autorecord: PgSQL - error inserting new record: " . $sql . " " . pg_last_error($sqlr));
        }
        $this->_rowChanged = array();
        $this->_originals = array();
        $this->state = 'saved_not_synced';        
        
        return pg_affected_rows($pgResult); 
    }


    // SAVE END
    
    
    
    
    
    
    public final function delete()
    {
        if ($this->_sqlResource instanceof mysqli) {
            $sql = 'DELETE FROM ' . $this->_tableName . ' WHERE ' . $this->_primaryKey . ' = ' 
                . (int)$this->getPrimaryKeyValue();
            if (!$this->_sqlResource->query($sql)) {
                throw new AutoDbException('AutoDb/Autorecord: mysqli - Error deleting row');
            }
            
            $this->setDeadReference();
            return;
        }
        
        if (AutoDb::isPgsqlResource($this->_sqlResource)) {
            $sql = 'DELETE FROM ' . $this->_tableName . ' WHERE ' . $this->_primaryKey . ' = ' 
                . (int)$this->getPrimaryKeyValue();
            if (!pg_query($sql)) {
                throw new AutoDbException('AutoDb/Autorecord: pgsql - Error deleting row');
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
        $this->state = 'dead';
        $this->_autoDb->_removeKey($this->_tableName, $primaryKeyWas);
    }
    
    public function isDeadReference()
    {
        return (bool)($this->_primaryKey === self::DEAD_REFERENCE_PK_VALUE || $this->state === 'dead');
    }
    
    /**
     * Temporary, undocumented and dangerous, using it for a migration script
     * @param int $key
     */
    public function rapePrimaryKeyForMultiInsertQuery($key)
    {
        $pk = (int)$key;
        $this->state = 'danger';
        $this->_attributes[$this->getPrimaryKey()] = $pk;
    }
    
    /**
     * Not really public, C++ style friend class emulated, practically private
     * Only working if called from $this->autoDb->replaceMysqliResource
     * @param mysqli $mysqli
     */
    public function _replaceMysqli($mysqli)
    {
        if (!$this->_autoDb->getSqlResReplacing()) {
            throw new AutoDbException('AutoDb/AutoRecord: can only call _replaceMysqli via AutoDb collector');
        }
        $this->_sqlResource = $mysqli; // same reference as AutoDb instance
    }

    /**
     * Not really public, C++ style friend class emulated, practically private
     * Only working if called from $this->autoDb->replacePgSqlResource
     * @param resource $pgSqlRes
     */    
    public function _replacePgSql($pgSqlRes)
    {
        if (!$this->_autoDb->getSqlResReplacing()) {
            throw new AutoDbException('AutoDb/AutoRecord: can only call _replacePgSql via AutoDb collector');
        }
        $this->_sqlResource = $pgSqlRes; // same reference as AutoDb instance
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
            }
            
            if (AutoDb::isPgsqlResource($sqlr)) {
                // set columns if first run
                if ($insertQuery === '') {
                    $insertQuery = $insertCommand . ' ' . pg_escape_string($autoRecord->getTableName()) . ' ';
                    
                    $colNames = '';

                    $comma = false;
                    foreach ($autoRecord->_columnRules as $key => $rules) {
                        if ($key === '__primarykey') { // not real column
                            continue;
                        }
                        // in postgres skip primary key unless forced (for all rows):
                        if ($key == $autoRecord->getPrimaryKey() && $autoRecord->getPrimaryKeyValue() == 0) {
                            continue;
                        }
                        
                        $columns[] = $key;
                        if (!$comma) {
                            $comma = true;
                        } else {
                            $colNames .= ',';
                        }
                        $colNames .= pg_escape_string($key);
                    }
                    $insertQuery .= "( $colNames ) VALUES ";
                }                
            }            
            
            if ($counter++ >= 1) {
                $insertQuery .= ','; // new row
            }
            
            $insertQuery .= ' ( ';
            foreach ($columns as $key => $col) {
                if ($key > 0) {
                    $insertQuery .= ' , ';
                }
                if (AutoDb::isPgsqlResource($sqlr)) {
                    if ($col == $autoRecord->getPrimaryKey() && $autoRecord->getPrimaryKeyValue() == 0) {
                        continue; // skipping isntead of nextval seq
                    }
                }
                
                $insertQuery .= $autoRecord->_getCommasAndEscapes($col, $autoRecord->_attributes[$col]);
            }
            $insertQuery .= ' ) ';
            
            // we don't know insert ID's, all references are dead :(
            $autoRecord->setDeadReference();
        }
        
        $insertQuery .= $suffix; // 'ON DUPLIACTE KEY UPDATE ... '
        
        if ($sqlr instanceof mysqli) {
            if (!$sqlr->query($insertQuery)) {
                throw new AutoDbException("AutoDb/Autorecord: saveMore(): MySQL - error inserting new records: " . $insertQuery . " " . $sqlr->error);
            }
            return $sqlr->affected_rows;
        }
        
        if (AutoDb::isPgsqlResource($sqlr)) {
            $pgResult = pg_query($sqlr, $insertQuery);
            if (!$pgResult) {
                throw new AutoDbException("AutoDb/Autorecord: saveMore(): PgSQL - error inserting new records: " . $sql . " " . pg_last_error($sqlr));
            }     

            return pg_affected_rows($pgResult);             
        }
        
        return 0;
    }
    
    
    /**
     * In case of uninserted items you may only want to see the query itself which would be generated by saveMore(). This method solves it
     * @param array $arrayOfAutoRecords - same AutoDb, same Connection, same TABLE, no other instances in the array
     * @param $insertCommand - INSERT INTO or REPLACE INTO or INSERT IGNORE INTO - for concurrent writes
     * @param $suffix - for example ' ON DUPLICATE KEY UPDATE last_saved = NOW() '
     * @return string
     * @throws AutoDbException - if there is an AutoRecord to update, this is for single insert query only
     */
    public static final function generateInsertQuery(array $arrayOfAutoRecords, $insertCommand = 'INSERT INTO', $suffix = '')
    {
        if (empty($arrayOfAutoRecords)) {
            return '';
        }
        $rowCount = 0;
        $tablename = '';
        $toInsert = array();
        $autoDb = null;
        $sqlr = null;
        foreach ($arrayOfAutoRecords as $autoRecord) {
            if (!($autoRecord instanceof AutoRecord)) {
                throw new AutoDbException('AutoDb/Autorecord: generateInsertQuery() should get an array of AutoRecord instances (also from same table)');
            }
            if ($tablename === '') {
                $tablename = $autoRecord->getTableName();
            }
            if ($tablename !== $autoRecord->getTableName()) {
                throw new AutoDbException('AutoDb/Autorecord: generateInsertQuery() should get an array of AutoRecord instances from same table');
            }
            if (is_null($autoDb)) {
                $autoDb = $autoRecord->_autoDb;
                $sqlr = $autoDb->getSqlResource();
            } else {
                if ($autoRecord->_autoDb !== $autoDb || $sqlr !== $autoRecord->getSqlResource()) {
                    throw new AutoDbException('AutoDb/Autorecord: This was a very dangerous call to the method generateInsertQuery(), aborting');
                }
            }
            
            
            if ($autoRecord->getPrimaryKeyValue() > 0 && $autoRecord->getState() !== 'danger') { // forced insert row
                throw new AutoDbException('AutoDb/Autorecord: generateInsertQuery() should only contain new lines to insert, nothing to update');
            } else {
                $toInsert[] = $autoRecord;
            }
        }
        
        // INSERT optimised, and return query string
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
            }
            
            if (AutoDb::isPgsqlResource($sqlr)) {
                // set columns if first run
                if ($insertQuery === '') {
                    $insertQuery = $insertCommand . ' ' . pg_escape_string($autoRecord->getTableName()) . ' ';
                    
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
                        $colNames .= pg_escape_string($key);
                    }
                    $insertQuery .= "( $colNames ) VALUES ";
                }                
            }             
            
            if ($counter++ >= 1) {
                $insertQuery .= ','; // new row
            }
            
            $insertQuery .= ' ( ';
            foreach ($columns as $key => $col) {
                if ($key > 0) {
                    $insertQuery .= ' , ';
                }
                $insertQuery .= $autoRecord->_getCommasAndEscapes($col, $autoRecord->_attributes[$col]);
            }
            $insertQuery .= ' ) ';
            
        }
        
        $insertQuery .= $suffix; // 'ON DUPLIACTE KEY UPDATE ... '
        
        return $insertQuery;
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
        
        if (AutoDb::isPgsqlResource($sqlr)) {
            $deleteQuery = 'DELETE FROM ' . pg_escape_string($tablename) . ' WHERE '
                . pg_escape_string($primaryKey) . ' IN (' . implode(',', $deleteIds) . ')';            
            
            $pgResult = pg_query($sqlr, $deleteQuery);
            if (!$pgResult) {
                throw new AutoDbException("AutoDb/Autorecord: PgSQL - error inserting new record: " . $sql . " " . pg_last_error($sqlr));
            }       

            return pg_affected_rows($pgResult);
        }
        
        throw new AutoDbException('AutoDb/Autorecord: deleteMore() - unknown error');
    }
    
}
