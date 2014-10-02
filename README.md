MySQLReplicaIntegrityCheck
==========================

## Goal

Performs an online replication consistency check by executing checksum queries on the master and all slaves, which produces different results on replicas that are inconsistent with the master.

## Prerequisites

* PHP 5.3 and above
* php-mysqli extension enabled
* MySQL database and at least 1 replica (all binary log format is supported (statement, row, mixed))

## Risk

* Adds load to the servers 
* Can acquire write lock on some data for long period if servers are overloaded

## How it works

todo

## Usage

* Download "MySQLReplicaIntegrityCheck.php"
* Edit configuration inside file, in section "configuration"
* Execute script

## Sample config

    #
    #   configuration
    #
    $master_link_host = '127.0.0.1';
    $master_link_user = 'root';
    $master_link_password = '';
    $master_link_port = '3306';
    $master_link_db = 'dbname1';

    $slave_config[0] = [];
    $slave_config[0]['host'] = '127.0.0.1';
    $slave_config[0]['user'] = 'root';
    $slave_config[0]['password'] = '';
    $slave_config[0]['port'] = '3307';
    $slave_config[0]['db'] = ' dbname1';
    
    $chunk_size = 100000; // how much rows examine per one query/lock. 
