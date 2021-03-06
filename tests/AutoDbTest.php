<?php

namespace AutoDb\Tests;
use PHPUnit\Framework\TestCase;
use AutoDb\AutoDb;
use AutoDb\AutoDbException;
use AutoDb\AutoRecord;
use Exception;
use redis;


/**
 * Unit tests for AutoDb
 *
 * @author danielszakal
 */
class AutoDbTest extends TestCase
{
    public $mysqli;
    public $mysqliOther;
    
    public $testAdb;
    public $testAdbOther;
    
    public $pgres;
    public $pgresConn;
    public $adbpg;
    
    public $exception;
    
    public $redis;
    
    
    // GITIGNORED FILE - ADD YOUR OWN TO TEST YOURSELF. SAMPLE:
    /*
        define('MYSQL_HOST', 'localhost');
        define('MYSQL_USER', 'youruser');
        define('MYSQL_PASSWORD', 'yourpassword');
        define('TEST_MYSQL', 1);

        define('TEST_PGSQL', 1);
        define('PGSQL_CONN_STRING', 'host=localhost user=postgres password=mypassword');
     */    
    
    // MYSQL START
    
    /**
     * It's more like an integration test than a unit test.
     * For all tests we need this to build up first.
     * Also we need to forced generate some edge cases.
     */
    public function testMysql()
    {
        
        // require_once(__DIR__ . '/../test_mysql_connection_credentials.php');
        // moved to bootstrap.php
        
        if (!TEST_MYSQL) {
            echo "Warning - skipping mysql test\n";
            $this->assertTrue(true);
            return;
        }
        
        $this->mysqli = mysqli_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD);
        
        $result = $this->mysqli->query('SELECT 1');
        $row = $result->fetch_assoc();
        $this->assertEquals($row[1], 1);
        
        $this->mysqli->query('DROP DATABASE IF EXISTS autodb_test;');
        $this->mysqli->query('DROP DATABASE IF EXISTS autodb_test_other;');
        $this->mysqli->query('CREATE DATABASE autodb_test;');
        $this->mysqli->query('CREATE DATABASE autodb_test_other;');
        
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379);
        
        $this->mysqli->query('USE autodb_test;');
        
        $this->testAdb = AutoDb::init($this->mysqli, $this->redis);
        
        $this->mysqliOther = mysqli_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, 'autodb_test_other');
        
        $this->testAdbOther = AutoDb::init($this->mysqliOther, $this->redis, 'redis_keys_wont_mismatch_as_this_optional_is_set');
        
        $this->createTables();
        $this->realTests();
        $this->insertAndUpdateTests();
        $this->concurrentWriteTests();
    }
    
    private function createTables()
    {
        $this->mysqli->query(
            "CREATE TABLE `clientinfo` (
                `client_id` int(11) NOT NULL AUTO_INCREMENT,
                `businessname` text,
                `username` VARCHAR(100),
                `passwordhash` text,
                `active` TINYINT DEFAULT 0,
                PRIMARY KEY (`client_id`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
        $this->mysqli->query(
            "
            CREATE TABLE `login_log` (
              `login_log_id` int(11) NOT NULL AUTO_INCREMENT,
              `login_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `reviewed_at` timestamp NULL,
              `client_id` int(11) DEFAULT NULL,
              `order` text,
              PRIMARY KEY (`login_log_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;

            "
        );
        
        $this->mysqli->query(
            "
            CREATE TABLE `unik` (
              `unik_id` int(11) NOT NULL AUTO_INCREMENT,
              `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL ON UPDATE CURRENT_TIMESTAMP,
              `request_count` int(11) NOT NULL DEFAULT 1,
              `uniq_part_1` VARCHAR(10),
              `uniq_part_2` INT,
              `just_a_number` INT DEFAULT 11,
              PRIMARY KEY (`unik_id`),
              UNIQUE KEY unik_key (uniq_part_1, uniq_part_2)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;

            "
        );        
        
        // we need a same name different table definition to test things are really working
        $this->mysqliOther->query(
            "CREATE TABLE `clientinfo` (
                `id_client` int(11) NOT NULL AUTO_INCREMENT,
                `business_name` text,
                `uname` VARCHAR(100),
                `passhash` text,
                `isactive` TINYINT DEFAULT 0,
                PRIMARY KEY (`id_client`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
        $this->mysqliOther->query("      
            CREATE TABLE `login_log` (
              `id_login_log` int(11) NOT NULL AUTO_INCREMENT,
              `logindate` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `reviewedat` timestamp NULL,
              `id_client` int(11) DEFAULT NULL,
              `group` text,
              PRIMARY KEY (`id_login_log`),
              CONSTRAINT `fk_login_log_hell` FOREIGN KEY (`id_client`) REFERENCES `clientinfo` (`id_client`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
        
        $this->mysqliOther->query("
            CREATE TABLE `with_decimal` (
                `id_with_decimal` int(11) NOT NULL AUTO_INCREMENT,
                `money` decimal,
                PRIMARY KEY (`id_with_decimal`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8;

            "
        );
        
        $this->mysqliOther->query("
            CREATE TABLE `only_other` (
                `id_only_other` int(11) NOT NULL AUTO_INCREMENT,
                `something` text,
                `else` double DEFAULT NULL,
                PRIMARY KEY (`id_only_other`)
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8;

            "
        );
    }
    
    private function realTests()
    {
        $this->exception = false;
        try {
            // not existing table definition
            $this->testAdb->newRow('wefef');
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        
        $this->exception = false;
        try {
            // table definition with decimal should throw exception
            $row = $this->testAdbOther->newRow('with_decimal');
            $row->attr('money', 11.23);
            $row->save();
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        
        $this->exception = false;
        try {
            // other db has the table only_other
            $row = $this->testAdbOther->newRow('only_other');
            $row->attr('something', 'hello');
            $row->save();
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertFalse($this->exception); // ASSERT FALSE
        
        $this->exception = false;
        try {
            // ... but testAdb's database does not have the table only_other
            $row = $this->testAdb->newRow('only_other');
            $row->attr('something', 'hello');
            $row->save();            
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        
        
        // DIFFERENT DB SAME TABLE DIFFRENT COLUMN START
        $this->exception = false;
        try {
            $row = $this->testAdb->newRow('clientinfo');
            $row->attr('businessname', 'xxx');
            $row->save();
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertFalse($this->exception);
        
        $this->exception = false;
        try {
            $row = $this->testAdbOther->newRow('clientinfo');
            $row->attr('business_name', 'yyy');
            $row->save();
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertFalse($this->exception);
        
        $this->exception = false;
        try {
            $row = $this->testAdbOther->newRow('clientinfo');
            $row->attr('businessname', 'xxx'); // column not existing
            // should throw exception even without saving
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        // DIFFERENT DB SAME TABLE DIFFRENT COLUMN END
        
        
        
        
        // not existing row
        $this->exception = false;
        try {
            $row = $this->testAdb->row('clientinfo', 'client_id', 9999);
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        
        // different newrows
        $this->exception = false;
        try {
            $rows = array();
            $rows[] = $this->testAdb->newRow('clientinfo');
            $rows[] = $this->testAdb->newRow('login_log');
            AutoRecord::saveMore($rows);
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        // same tablename, different autodb instance
        $this->exception = false;
        try {
            $rows = array();
            $rows[] = $this->testAdb->newRow('clientinfo');
            $rows[] = $this->testAdbOther->newRow('clientinfo');
            AutoRecord::saveMore($rows);            
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        // new row, not existing column
        $this->exception = false;
        try {
            $row = $this->testAdb->newRow('clientinfo');
            $row->dbAttrForce('xxx');         
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);        
    }
    
    public function insertAndUpdateTests()
    {
        $row = $this->testAdb->newRow('clientinfo');
        $row->attr('businessname', 'tester');
        $row->attr('username', 'usertester');
        $row->attr('passwordhash', 'abdbabcbacbdbadbad12');
        $row->save();
        
        $row2 = $this->testAdbOther->newRow('clientinfo');
        $row2->attr('business_name', 'tester');
        $row2->attr('uname', 'usertester');
        $row2->attr('passhash', 'abdbabcbacbdbadbad12');
        $row2->save();
        
        // double test
        $rowx = $this->testAdbOther->newRow('only_other');
        $rowx->attr('else', 2.3);
        $rowx->save();
        $this->assertEquals($rowx->attr('else'), 2.3);
        $rowx->forceReloadAttributes();
        $this->assertEquals($rowx->attr('else'), 2.3);
        
        $logRows = array();
        $logRows[0] = $this->testAdb->newRow('login_log');
        $logRows[0]->attr('reviewed_at', 'NOW()');
        $logRows[0]->attr('client_id', $row->attr('client_id'));
        
        $logRows[1] = $this->testAdb->newRow('login_log');
        $logRows[1]->attr('reviewed_at', '2019-01-01 11:11:11');
        $logRows[1]->attr('client_id', $row->attr('client_id'));
        
        AutoRecord::saveMore($logRows);
        
        $logRows = array();
        $logRows[0] = $this->testAdbOther->newRow('login_log');
        $logRows[0]->attr('reviewedat', 'NOW()');
        $logRows[0]->attr('id_client', $row2->attr('id_client'));
        
        $logRows[1] = $this->testAdbOther->newRow('login_log');
        $logRows[1]->attr('reviewedat', '2019-01-01 11:11:11');
        $logRows[1]->attr('id_client', $row2->getPrimaryKeyValue());
        
        AutoRecord::saveMore($logRows);
        
        
        //edit
        
        $row = $this->testAdbOther->row('clientinfo', 'id_client', 2);
        
        $this->assertEquals($row->attr('isactive'), 0);
        $this->assertEquals($row->getState(), 'saved_not_synced');
        $row->forceReloadAttributes();
        $this->assertEquals($row->getState(), 'synced');
        $row->attr('isactive', 1);
        $this->assertEquals($row->attr('isactive'), 1);
        // this goes wrong if $row->forceReloadAttributes(); does not run, as (previous) save didn't do select to get default inserted val
        $this->assertEquals($row->dbAttr('isactive'), 0);
        $this->assertEquals($row->dbAttrForce('isactive'), 0);
        $row->save();
        $this->assertEquals($row->attr('isactive'), 1);
        $this->assertEquals($row->dbAttr('isactive'), 1);
        $this->assertEquals($row->dbAttrForce('isactive'), 1);
        
        // reconnect test
        $this->mysqli->close();
        $newmysqli = mysqli_connect(MYSQL_HOST, MYSQL_USER, MYSQL_PASSWORD, 'autodb_test');
        
        // WRONG WAY test
        // $row should not be able to reconnect and throw an exception, that is AutoDb object's task
        $this->exception = false;
        try {
            $row->_replaceMysqli($newmysqli);
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);

        // GOOD WAY test
        $this->testAdb->replaceMysqliResource($newmysqli); // everything should be still working after reconnect
        
        $row = $this->testAdb->row('clientinfo', 'client_id', 1);
        $row->attr('username', 'IamUpdated');
        
        $rows = array($row);
        
        for ($i = 3; $i <= 100; ++$i) {
            $newrow = $this->testAdb->newRow('clientinfo');
            $newrow->attr('businessname', 'mŰltiinsert_' . $i);
            $newrow->attr('username', 'usertester_' . $i);
            $newrow->attr('passwordhash', 'abdbabcbacbdbadbad12');
            $rows[] = $newrow;
        }
        
        // generateInsertQuery() must give us an exception as there are rows to update
        $this->exception = false;
        try {
            AutoRecord::generateInsertQuery($rows);
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);        
        
        $changedRows = AutoRecord::saveMore($rows);
        $this->assertEquals($changedRows, 99);
        
        // limit and page test
        $arr = $this->testAdb->rowsArray('clientinfo', '1 = 1', 10, 3);
        $this->assertEquals(count($arr), 10);
        
        // fetch latest 5 of in serted 98 + 2 earlier
        $rowsArray = $this->testAdb->rowsArray('clientinfo', 'client_id > 95');
        $this->assertEquals(count($rowsArray), 5);
        
        $row = $this->testAdb->row('clientinfo', 'client_id', 96);
        $this->assertEquals($row->attr('businessname'), 'mŰltiinsert_96');
        
        $row->attr('businessname', 'newname');
        $this->assertEquals($row->attr('businessname'), 'newname');
        $this->assertEquals($row->dbAttr('businessname'), 'mŰltiinsert_96');
        $this->assertEquals($row->dbAttrForce('businessname'), 'mŰltiinsert_96');
        
        $deletedRows = AutoRecord::deleteMore($rowsArray);
        $this->assertEquals($deletedRows, 5);
        $result = $newmysqli->query('SELECT MAX(client_id) as maxclientid FROM clientinfo');
        $this->assertEquals($result->fetch_assoc()['maxclientid'], 95); // this means deletion done
        
        // $row is a dead reference now, is it throwing after deletion?
        $this->exception = false;
        try {
            $shouldNotExist = $row->attr('businessname');
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        // test empty
        $this->assertEquals(AutoRecord::saveMore(array()),0);
        $this->assertEquals(AutoRecord::deleteMore(array()),0);
        $this->assertEquals(AutoRecord::generateInsertQuery(array()),''); // empty query string
        
        $this->mysqli = $newmysqli; // needed later, hacked lost connection tested already
    }
    
    public function concurrentWriteTests() 
    {
        $row1 = $this->testAdb->newRow('unik');
        $row1->attr('uniq_part_1', 'xxx');
        $row1->attr('uniq_part_2', 10);
        $row1->save();
        $row1->forceReloadAttributes();
        
        $row2 = $this->testAdb->newRow('unik');
        $row2->attr('uniq_part_1', 'xxx');
        $row2->attr('uniq_part_2', 20);
        $row2->attr('just_a_number', 21);
        $row2->save();
        $row2->forceReloadAttributes();
        
        sleep(2);
        
        $row3 = $this->testAdb->newRow('unik');
        $row3->attr('uniq_part_1', 'xxx');
        $row3->attr('uniq_part_2', 10);
        
        $this->exception = false;
        try {
            $row3->save();
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        sleep(2);
        
        $row4 = $this->testAdb->newRow('unik');
        $row4->attr('uniq_part_1', 'xxx');
        $row4->attr('uniq_part_2', 20);
        $row4->attr('just_a_number', 42);
        AutoRecord::saveMore(array($row4), 'REPLACE INTO');

        try {
            $shouldBeUnavailable = $row4->attr('just_a_number'); // dead reference
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);
        
        $uniqRecord = $this->testAdb->rowsArray('unik', "uniq_part_1 = 'xxx' AND uniq_part_2 = 20")[0];
        // $row2->forceReloadAttributes(); // replace into changed primary_key, that reference is practically dead, Exception
        $this->assertEquals($uniqRecord->attr('just_a_number'), 42);
        
        $row5 = $this->testAdb->newRow('unik');
        $row5->attr('uniq_part_1', 'xxx');
        $row5->attr('uniq_part_2', 30);
        $row5->attr('just_a_number', 17);
        $row5->save();
        $row5->forceReloadAttributes();
        
        sleep(2);
        
        $row6 = $this->testAdb->newRow('unik');
        $row6->attr('uniq_part_1', 'xxx');
        $row6->attr('uniq_part_2', 20);
        $row6->attr('just_a_number', 44);
        AutoRecord::saveMore(array($row6), 'INSERT IGNORE INTO');
        $uniqRecord = $this->testAdb->rowsArray('unik', "uniq_part_1 = 'xxx' AND uniq_part_2 = 30")[0];
        $row5->forceReloadAttributes();
        $this->assertEquals($uniqRecord->attr('just_a_number'), 17);
        
        $row7 = $this->testAdb->newRow('unik');
        $row7->attr('uniq_part_1', 'xxx');
        $row7->attr('uniq_part_2', 10);
        $this->assertEquals(
            AutoRecord::generateInsertQuery(array($row7), 'INSERT INTO', 'ON DUPLICATE KEY UPDATE request_count = request_count + 1'),
            "INSERT INTO unik ( `unik_id`,`created_at`,`updated_at`,`request_count`,`uniq_part_1`,`uniq_part_2`,`just_a_number` ) VALUES  ( 0 , CURRENT_TIMESTAMP , NULL , 1 , 'xxx' , 10 , NULL ) ON DUPLICATE KEY UPDATE request_count = request_count + 1");
        AutoRecord::saveMore(array($row7), 'INSERT INTO', 'ON DUPLICATE KEY UPDATE request_count = request_count + 1');
        
        $this->assertEquals($row1->attr('request_count'), 1);
        $this->assertEquals($row1->dbAttrForce('request_count'), 2);
        $row1->forceReloadAttributes();
        $this->assertEquals($row1->attr('request_count'), 2);
        
        // insert more query only test, forcing primary key
        $rowx = $this->testAdb->newRow('unik');
        $rowy = $this->testAdb->newRow('unik');
        
        $rowx->rapePrimaryKeyForMultiInsertQuery(999);
        $this->assertEquals($rowx->getState(), 'danger');
        $this->assertEquals($rowy->getState(), 'new');
        
        $query = AutoRecord::generateInsertQuery(array($rowy, $rowx));
        if (!$this->mysqli->query($query)) {
            $this->assertEquals(true, false); // should not be here
        }
        $result = $this->mysqli->query('SELECT MAX(unik_id) as maxuniqid FROM unik');
        $this->assertEquals($result->fetch_assoc()['maxuniqid'], 999); // this means forced primary key worked
        
        $row1000 = $this->testAdb->newRow('unik');
        $row1000->attr('uniq_part_1', 'dddd');
        $row1000->attr('uniq_part_2', 1000);
        // before save
        $this->assertEquals($row1000->attr('just_a_number'), NULL);
        $this->assertEquals($row1000->attr('request_count'), NULL);   
        // after save - did not reread
        $row1000->save();
        $this->assertEquals($row1000->attr('just_a_number'), NULL);
        $this->assertEquals($row1000->attr('request_count'), NULL);
        // after save and reread
        $row1000->forceReloadAttributes();
        $this->assertEquals($row1000->attr('just_a_number'), 11); // VALUES didn't contain column
        $this->assertEquals($row1000->attr('request_count'), 1); // as not nullable, took the default 1
        
        $row1001 = $this->testAdb->newRow('unik');
        $row1001->attr('uniq_part_1', 'dddd');
        $row1001->attr('uniq_part_2', 1001);
        // before save
        $this->assertEquals($row1001->attr('just_a_number'), NULL);
        $this->assertEquals($row1001->attr('request_count'), NULL);   
        // after save - did not reread
        AutoRecord::saveMore(array($row1001)); // dead reference, reread
        
        $reReadRow1001 = $this->testAdb->row('unik', 'unik_id', 1001);
        $this->assertEquals($reReadRow1001->attr('just_a_number'), NULL); // VALUES did contain column - forced NULL instead of DEFAULT 
        //(multiinsert can't skip cols)
        $this->assertEquals($reReadRow1001->attr('request_count'), 1); // as not nullable, took the default 1     
    }
    
    // MYSQL END
    
    
    
    
    
    
    
    
    
    
    
    
    
    // POSTGRESQL START
    
    public function testPgSql()
    {
        if (!TEST_PGSQL) {
            echo "Warning - skipping pgsql test\n";
            $this->assertTrue(true);
            return;
        }
        
        $this->pgresConn = pg_connect(PGSQL_CONN_STRING);
        
        pg_query($this->pgresConn, 'DROP DATABASE IF EXISTS adbtst');
        pg_query($this->pgresConn, 'CREATE DATABASE adbtst');
        
        $this->pgres = pg_connect(PGSQL_CONN_STRING . ' dbname=adbtst');
        
        $this->adbpg = AutoDb::init($this->pgres, $this->redis, 'pgdb');
        $this->adbpg->setOnDestructDisconnect(true);
        
        $this->pCreateTables();
        $this->pBaseTests();
        $this->pConcurrencyTests();
        
        // pg_close($this->pgres); // autodb destruct should solve this
        pg_close($this->pgresConn);
        
    }
    
    private function pCreateTables()
    {
        pg_query($this->pgres, "CREATE TABLE clientinfo (
                id_client BIGSERIAL PRIMARY KEY,
                businessname text,
                username VARCHAR(100),
                passwordhash text,
    			created_at timestamp with time zone NOT NULL DEFAULT NOW(),
    			modified_at timestamp with time zone DEFAULT NULL,
    			numx INT NOT NULL DEFAULT 11,
    			numy INT DEFAULT 3,
    			numz INT NOT NULL,
    			type text,
                active INT DEFAULT 0
              );");
        
        pg_query($this->pgres, "CREATE FUNCTION set_updated_timestamp()
                  RETURNS TRIGGER
                  LANGUAGE plpgsql
                AS $$
                BEGIN
                  NEW.modified_at := now();
                  RETURN NEW;
                END;
                $$;
        ");
        
        pg_query($this->pgres, "CREATE TRIGGER test_table_update_timestamp
  				BEFORE UPDATE ON clientinfo
  				FOR EACH ROW EXECUTE PROCEDURE set_updated_timestamp();
        "); 
        
        pg_query($this->pgres, "CREATE TABLE unikp (
                id_unikp BIGSERIAL PRIMARY KEY,
                created_at timestamp NOT NULL DEFAULT NOW(),
                request_count int NOT NULL DEFAULT 1,
                uniq_part_1 VARCHAR(10),
                uniq_part_2 INT,
                just_a_number INT DEFAULT 11,
                testjson json NOT NULL DEFAULT '{}',
                testjsontwo json DEFAULT '{}',
                UNIQUE (uniq_part_1, uniq_part_2)
            );            
        ");
    }
    
    private function pBaseTests()
    {
        $row = $this->adbpg->newRow('clientinfo');
        $row->attr('businessname', 'tester');
        $row->attr('username', 'usertester');
        $row->attr('passwordhash', 'abdbabcbacbdbadbad12');
        $row->attr('numz', '16');
        $row->save();
        
        $rowSame = $this->adbpg->row('clientinfo', 'id_client', 1);
        
        $this->assertEquals($rowSame->attr('created_at'), null);
        
        $this->assertEquals($rowSame->attr('numy'), NULL); // default 3 saved, but object doesn't know
        $this->assertEquals($rowSame->dbAttrForce('numy'), 3); // but we can force load col from db
        
        $rowSame->forceReloadAttributes();
        $this->assertEquals($rowSame->attr('numz'), 16);
        $createdAtUpdated = (bool)((int)$rowSame->attr('created_at') >= 2017);
        $this->assertTrue($createdAtUpdated);
        $this->assertNull($row->attr('modified_at'));
        
        $rowSame->attr('username', 'ichanged');
        $rowSame->save();
        // triggered save - object has no idea
        $this->assertNull($row->attr('modified_at'));
        $row->forceReloadAttributes();
        $modifiedAtUpdated = (bool)((int)$rowSame->attr('modified_at') >= 2017);
        $this->assertTrue($modifiedAtUpdated);        
        
        $row2 = $this->adbpg->newRow('clientinfo');
        $row2->attr('businessname', 'tester2');
        $row2->attr('username', 'usertester2');
        $row2->attr('passwordhash', 'abdbabcbacbdbadbad122');
        $row2->attr('numz', '16');
        $row2->save();   
        
        $row3 = $this->adbpg->newRow('clientinfo');
        $row3->attr('businessname', 't3ster');
        $row3->attr('username', 'us3rte');
        $row3->attr('passwordhash', 'abdbabcbacbdbadbad123');
        $row3->attr('numz', '19');
        $row3->save();          
        
        $this->assertEquals($row3->attr('id_client'), 3);
        
        $row3->delete();
        
        $test = pg_query($this->pgres, 'SELECT count(*) as cnt FROM clientinfo');
        
        $this->assertEquals(pg_fetch_assoc($test)['cnt'], 2);
        
        $row2->attr('passwordhash', 'changedregewrg');
        $array = array($row2);
        
        for ($i = 0; $i < 5; ++$i) {
            $row = $this->adbpg->newRow('clientinfo');
            $row->attr('businessname', 'auto' . $i);
            $row->attr('username', 'ausertester' . $i);
            $row->attr('passwordhash', 'abdbabcbacbdbadbad12x' . $i);
            $row->attr('numz', 100 + $i);
            $array[] = $row;
        }
        
        $this->assertEquals(AutoRecord::saveMore($array), 6); // total 6 rows should be effected
        
        $arr = $this->adbpg->rowsArray('clientinfo', '1 = 1', 3, 2);
        $this->assertEquals(count($arr), 3);
        
        $this->assertEquals($row2->dbAttrForce('passwordhash'), 'changedregewrg'); // saved
        
        $rowsArr = $this->adbpg->rowsArray('clientinfo', "businessname LIKE 'auto%'");
        
        $this->assertEquals(count($rowsArr), 5);
        
        $delRows = array($rowsArr[1], $rowsArr[3]);
        $this->assertEquals(AutoRecord::deleteMore($delRows), 2);
        
        $this->assertTrue($rowsArr[1]->isDeadReference());
        $this->assertFalse($rowsArr[2]->isDeadReference());
        
        $test = pg_query($this->pgres, 'SELECT count(*) as cnt FROM clientinfo');
        
        $this->assertEquals(pg_fetch_assoc($test)['cnt'], 5); // was 3, 1 deleted, was 2, added 5, was 7, 2 deleted, so 5
        
        $rowRaped = $this->adbpg->newRow('clientinfo');
        $rowRaped->attr('businessname', 'testerd');
        $rowRaped->attr('username', 'usertesteqr');
        $rowRaped->attr('passwordhash', 'abdbabcbacbdbadbqad12');
        $rowRaped->attr('numz', '16');
        $rowRaped->rapePrimaryKeyForMultiInsertQuery(-2);
        
        $rowRaped2 = $this->adbpg->newRow('clientinfo');
        $rowRaped2->attr('businessname', 'tawest');
        $rowRaped2->attr('username', 'useqwrqwrtester');
        $rowRaped2->attr('passwordhash', 'abdbabcbacebdbadbad12');
        $rowRaped2->attr('numz', 26);
        $rowRaped2->rapePrimaryKeyForMultiInsertQuery(-3);
        
        $arr = array($rowRaped, $rowRaped2);
        
        AutoRecord::saveMore($arr);
        
        $test = pg_query($this->pgres, 'SELECT MIN(id_client) as minpk FROM clientinfo');
        
        $this->assertEquals(pg_fetch_assoc($test)['minpk'], -3); // raped primary key in multi insert       
        $rowj = $this->adbpg->newRow('unikp');
        $rowj->attr('uniq_part_1', 'jnkwa');
        $rowj->attr('uniq_part_2', 117);
        $rowj->attr('testjsontwo', json_encode(array('hello' => 'IamTest')));
        $rowj->save();
        // echo $rowj->attr('testjsontwo') . "\n";
        // echo $rowj->dbAttrForce('testjsontwo') . "\n";
        $this->assertEquals($rowj->dbAttrForce('testjsontwo'), $rowj->attr('testjsontwo'));
        $this->assertEquals($rowj->dbAttrForce('testjsontwo'), '{"hello":"IamTest"}');
    }
    
    public function pConcurrencyTests()
    {
        $row1 = $this->adbpg->newRow('unikp');
        $row1->attr('uniq_part_1', 'xxx');
        $row1->attr('uniq_part_2', 10);
        $row1->save();
        $row1->forceReloadAttributes();
        
        $row2 = $this->adbpg->newRow('unikp');
        $row2->attr('uniq_part_1', 'xxx');
        $row2->attr('uniq_part_2', 20);
        $row2->attr('just_a_number', 21);
        $row2->save();
        $row2->forceReloadAttributes();

        sleep(2);
        
        $row3 = $this->adbpg->newRow('unikp');
        $row3->attr('uniq_part_1', 'xxx');
        $row3->attr('uniq_part_2', 10);
        
        $this->exception = false;
        try {
            $row3->save();
        } catch (AutoDbException $e) {
            $this->exception = true;
        }
        $this->assertTrue($this->exception);

        $row4 = $this->adbpg->newRow('unikp');
        $row4->attr('uniq_part_1', 'xxx');
        $row4->attr('uniq_part_2', 20);
        $row4->attr('just_a_number', 24);
        
        AutoRecord::saveMore(array($row4), 'INSERT INTO', 'ON CONFLICT (uniq_part_1, uniq_part_2) DO UPDATE SET request_count = unikp.request_count + 1;');
        
        $row2->forceReloadAttributes();
        $this->assertEquals($row2->attr('just_a_number'), 21); // was not in "on conflict"
        $this->assertEquals($row2->attr('request_count'), 2); // 1 + 1 from "ON CONFLICT" clause
        $this->assertTrue($row4->isDeadReference());
    }
    
    // POSTGRESQL END
    
}
