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
    
    public $exception;
    
    public $redis;
    
    /**
     * It's more like an integration test than a unit test.
     * For all tests we need this to build up first.
     * Also we need to forced generate some edge cases.
     */
    public function testMysql()
    {
        
        // GITIGNORED FILE - ADD YOUR OWN TO TEST YOURSELF. SAMPLE:
        /*
            define('MYSQL_HOST', 'localhost');
            define('MYSQL_USER', 'youruser');
            define('MYSQL_PASSWORD', 'yourpassword');
         */
        
        // require_once(__DIR__ . '/../test_mysql_connection_credentials.php');
        // moved to bootstrap.php
        
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
    }
    
}
