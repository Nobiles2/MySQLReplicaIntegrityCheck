MySQLReplicaIntegrityCheck
==========================

## Goal

Performs an online replication consistency check by executing checksum queries on the master and all slaves, and find out which of the replicas are inconsistent with the master.

## Prerequisites

* PHP 5.3 and above
* php-mysqli extension enabled
* MySQL database and at least 1 replica (all binary log format is supported (statement, row, mixed))
* All tables have to be in InnoDB format

## Risk

* Adds load to the servers 
* Can cause write lock on some data for longer periods of time if replicas are overloaded (if slave’s log pos won't catch’up master's log pos fast enough)

## How it works

1) This tool connects simultaneously to all data servers and creates ‘__integrity_check_dummy__’ table (an internal index that is used for finding out which data package is currently processed and if data on replicas already “caught up”) on each of them.

2) In the next step, it focuses on one table, and locks (only for writing) a single data package (i.e. first 10k rows).

3) Index on ‘__integrity_check_dummy__’ table is incremented, which also causes incrementation of master_log_pos, and it forces MySQL to replicate this change on Slave servers.

4) Now, it’s monitoring replication progress on Slave servers, which means, it waits until index, on replicated table, __integrity_check_dummy__, is the same as the one on Master. If databases aren’t overloaded, this shouldn’t take much time.

5) When Slave catches up with Master’s data, comes actual checksum testing of both (Master and Slave) sides. (Because data is locked on Master, and Slave caught up, a test on that data package can be performed).

6) Right after checking, data on Master is unlocked and whole process is repeated on another data package.

Data packaging is done according to database’s primary keys or unique not nulls, if there is no primary. And if there are no keys, then it’s necessary to lock whole table, for the time of checksum testing.

Integrity tests can be performed on any MySQL configuration. That means binary log modes: “statement”, “row” and “mixed” are supported.
Test can be performed even when database is replicated with another tool, i.e. Tungsten Replicator.

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


To add more slaves create another $slave_config[1], $slave_config[2]…  array’s.

Personally, I've tested about 60GB database with this tool in about one hour (on heavy - production server).
