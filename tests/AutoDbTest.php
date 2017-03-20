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
        $row->attr('isactive', 1);
        $this->assertEquals($row->attr('isactive'), 1);
        $this->assertEquals($row->dbAttrForce('isactive'), 0);
        $row->save();
        $this->assertEquals($row->attr('isactive'), 1);
        $this->assertEquals($row->dbAttr('isactive'), 1);
        $this->assertEquals($row->dbAttrForce('isactive'), 1);
        
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
        $result = $this->mysqli->query('SELECT MAX(client_id) as maxclientid FROM clientinfo');
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
    }
    
}
