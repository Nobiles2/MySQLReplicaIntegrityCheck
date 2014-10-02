#!/usr/bin/php
<?php

    #
    #   configuration
    #
    $master_link_host = '127.0.0.1';
    $master_link_user = 'root';
    $master_link_password = '';
    $master_link_port = '3306';
    $master_link_db = 'sample_database';

    $slave_config[0] = [];
    $slave_config[0]['host'] = '127.0.0.1';
    $slave_config[0]['user'] = 'root';
    $slave_config[0]['password'] = '';
    $slave_config[0]['port'] = '3307';
    $slave_config[0]['db'] = 'sample_database';
    
    $chunk_size = 100000; // rows
    
    
    function getmicrotime()
    { 
        list($usec, $sec) = explode(" ",microtime()); 
        return ((float)$usec + (float)$sec); 
    }     
    $time_start = getmicrotime();
    $integrity_result = true;
    $integrity_result_bad_tables = [];
    
    
    #
    #   connect's
    #
    $master_link = mysqli_connect($master_link_host,$master_link_user,$master_link_password,$master_link_db,$master_link_port) or die('Master connection fails.');
    
    $slave_links = [];
    foreach( $slave_config as $k => $slave_config1 )
    {
        $slave_links[$k] = mysqli_connect($slave_config1['host'],$slave_config1['user'],$slave_config1['password'],$slave_config1['db'],$slave_config1['port']) or die("Slave #{$k} connection fails.");
    }
    
    
    #
    #   create dummy table, for artificial master_log incrementation (it needs to change due to chunk locking process; without it i can't guarantee integrity on chunk checksum checking)
    #
    $query = "DROP TABLE IF EXISTS __integrity_check_dummy__;";
    $result = mysqli_query($master_link, $query);
    if( mysqli_errno( $master_link ) != 0 )
    {
        echo __LINE__ . ' ' . mysqli_error($master_link);
        exit();
    }   
    
    $query = "CREATE TABLE __integrity_check_dummy__ ( n BIGINT ) SELECT 0 AS n;";
    $result = mysqli_query($master_link, $query);
    if( mysqli_errno( $master_link ) != 0 )
    {
        echo __LINE__ . ' ' . mysqli_error($master_link);
        exit();
    }   
    
    
    #
    #   function to increment dummy table (and master log pos)
    #
    function dummy_increment()
    {
        global $master_link;
        
        $query = "UPDATE __integrity_check_dummy__ SET n = n + 1";
        $result = mysqli_query($master_link, $query);
        if( mysqli_errno( $master_link ) != 0 )
        {
            echo __LINE__ . ' ' . mysqli_error($master_link);
            exit();
        }           
    }

    
    #
    #   function to get actual dummy pos on chosen server
    #   used in loop, that waits for slaves to catch up master dummy pos nr
    #
    function dummy_getn( $link )
    {
        $query = "SELECT n FROM __integrity_check_dummy__ LIMIT 1";
        $result = mysqli_query($link, $query);
        if( mysqli_errno( $link ) != 0 )
        {
            echo __LINE__ . ' ' . mysqli_error($link);
            exit();
        } 
        
        if( ! $result instanceof mysqli_result )
        {
            echo __LINE__ . ' ' . mysqli_error($link);
            exit();
        }
        
        list( $n ) = mysqli_fetch_row($result);
        
        return $n;
    }
    
    
    #
    #   group_concat_max_len needs to be at least $chunk_size*32 (length of md5 sum)
    #   on each server
    # 
    $query = "SET group_concat_max_len=".(($chunk_size*(32+1))+1); 
    
    $result = mysqli_query($master_link, $query);
    if( mysqli_errno( $master_link ) != 0 )
    {
        echo __LINE__ . ' ' . mysqli_error($master_link);
        exit();
    }       
    
    foreach( $slave_links as $link )
    {
        $result = mysqli_query($link, $query);
        if( mysqli_errno( $link ) != 0 )
        {
            echo __LINE__ . ' ' . mysqli_error($master_link);
            exit();
        }               
    }
    
    
    #
    #   get ALL tables from selected schema with information about PRI/UNI column_names
    #
    $query = "
        SELECT 
            T.TABLE_NAME,
            C.COLUMN_NAMES
        FROM 
            information_schema.TABLES T 
            LEFT JOIN (
                SELECT 
                    C.TABLE_NAME,
                    GROUP_CONCAT(C.COLUMN_NAME) AS COLUMN_NAMES
                FROM 
                    information_schema.COLUMNS C 
                WHERE 
                    C.TABLE_SCHEMA = '".mysqli_real_escape_string( $master_link, $master_link_db )."' 
                GROUP BY 
                    C.TABLE_NAME
            ) C ON C.TABLE_NAME = T.TABLE_NAME
        WHERE 
            T.TABLE_SCHEMA = '".mysqli_real_escape_string( $master_link, $master_link_db )."' 	
            AND T.TABLE_TYPE = 'base table'        
    ";
    $result = mysqli_query($master_link, $query);
    
    if( ! $result instanceof mysqli_result )
    {
        echo __LINE__ . ' ' . mysqli_error($master_link);
        exit();
    }
    
    if( $result->num_rows == 0 )
    {
        echo "No tables to compare";
        exit();
    }
    
    while( $data = mysqli_fetch_assoc( $result ))
    {
        $tables_data[] = $data;
    }
    
    foreach( $tables_data as &$table )
    {
        #
        #   determine how to split table for checking integrity - by primary key, unique one (which) or just limit
        #
        $query = "SHOW INDEX FROM `".mysqli_real_escape_string($master_link, $table['TABLE_NAME'])."`";
        
        $result = mysqli_query($master_link, $query);
        
        if( ! $result instanceof mysqli_result )
        {
            echo __LINE__ . ' ' . mysqli_error($master_link);
            exit();
        }        
        
        if( $result->num_rows == 0 )
        {
            $table['index_details'] = null;
            continue;
        }
        
        $table['table_index_data']=[];
        $table['key_column_names']=[];
        $table['key_name']='';
        $table['alg']=null;
        
        while( $data = mysqli_fetch_assoc( $result ))
        {
            $table['table_index_data'][] = $data;
            
            if( $data['Key_name'] == 'PRIMARY' && $table['alg']==null )
            {
                $table['alg'] = 'PRIMARY';
                $table['key_name'] = '::PRIMARY::';
            }
            
            if( $data['Key_name'] != 'PRIMARY' && $data['Non_unique']==0 && $table['alg']==null )
            {
                $table['alg'] = 'UNIQUE_NOT_NULL';
                $table['key_name'] = $data['Key_name'];
            }
            
            if( $data['Key_name'] != 'PRIMARY' && $data['Non_unique']==1 && $table['alg']==null )
            {
                $table['alg'] = 'MYSQL_CHECKSUM';
                $table['key_name'] = $data['Key_name'];
            }
            
            switch( $table['alg'] )
            {
                case 'PRIMARY':
                    if( $data['Key_name'] == 'PRIMARY' )
                    {
                        $table['key_column_names'][] = $data['Column_name'];
                    }
                    break;
                
                case 'UNIQUE_NOT_NULL':
                    if( $data['Key_name'] == $table['key_name'] )
                    {
                        $table['key_column_names'][] = $data['Column_name'];
                    }
                    break;
            }
        }
        
        if( $table['alg'] == null )
        {
            $table['alg'] = 'ALL'; //can be lenghty, but there's no other way to do it
        }
        
        //echo $table['TABLE_NAME'] . ' : ' . $table['alg'] . "\r\n"; 
        
         
        #
        #   test...
        #
        switch( $table['alg'] )
        {
            case 'PRIMARY':
            case 'UNIQUE_NOT_NULL':
                $first=true;
                $last_row_data=[];
                $rows_processed=0;
                for(;;)
                {
                    $time_chunk_start = getmicrotime();
                    
                    // i have to retrieve MAX from each column from PRI/UNIQ key to determine which chunk i have to check in this iteration
                    $query_max_values='';
                    foreach( $table['key_column_names'] as $temp_key_column_name )
                    {
                        $query_max_values .= ", MAX({$temp_key_column_name}) AS {$temp_key_column_name}";
                    }
                    
                    $query_where = '';

                    // generate conditions ( WHERE ) for spliting table into smaller chunks by using key(s)
                    if( !$first )
                    {
                        $query_where = 'WHERE FALSE ';
                        $key_column_count = count($table['key_column_names']);
                        
                        for($i=$key_column_count-1; $i>=0; $i--)
                        {
                            $query_where .= ' OR ( TRUE ';
                            
                            for($j=$i; $j>=0; $j--)
                            {
                                $temp_key_column_name = $table['key_column_names'][$j];
                                        
                                if( $j == $i )
                                {
                                    $query_where .= " AND {$temp_key_column_name} > '" . mysqli_real_escape_string( $master_link, $last_row_data[$temp_key_column_name] ) . "'";
                                }
                                else
                                {
                                    $query_where .= " AND {$temp_key_column_name} = '" . mysqli_real_escape_string( $master_link, $last_row_data[$temp_key_column_name] ) . "'";
                                }
                            }                            
                            
                            $query_where .= ' ) ';
                        }                        
                    }
                    
                    $table_column_names_ifnull = explode(',', $table['COLUMN_NAMES'] );
                    foreach( $table_column_names_ifnull as &$t ){ $t = "IFNULL(`{$t}`,'')"; }
                    $table_column_names_ifnull = implode(',',$table_column_names_ifnull);
                    
                    $query = "
                        SELECT
                            MD5( GROUP_CONCAT(T.checksum) ) AS checksum_all
                            $query_max_values
                        FROM
                            (
                                SELECT 
                                    MD5(CONCAT(".$table_column_names_ifnull.")) AS `checksum`,
                                    ".implode(',',$table['key_column_names'])." 
                                FROM 
                                    `".$table['TABLE_NAME']."`
                                {$query_where}
                                ORDER BY 
                                    ".implode(',',$table['key_column_names'])." 
                                LIMIT 
                                    0, {$chunk_size}
                                FOR UPDATE
                            ) T
                        HAVING
                            checksum_all IS NOT NULL
                    ";
                    // "for update" means other sessions can retrive data, but not modify them (will wait for unlock)
                    //echo $query;
                    
                                    
                    #
                    #   begin transaction (to hold lock until slave catches up master's log pos)
                    #
                    mysqli_query($master_link, "START TRANSACTION");
                    if( mysqli_errno( $master_link ) != 0 )
                    {
                        echo __LINE__ . ' ' . mysqli_error($master_link);
                        exit();
                    }
                    
                                    
                    #
                    #   check on master and lock rows
                    #
                    $result = mysqli_query($master_link, $query);
                    
                    if( ! $result instanceof mysqli_result )
                    {
                        echo __LINE__ . ' ' . mysqli_error($master_link);
                        exit();
                    }        

                    if( $result->num_rows == 0 )
                    {
                        break;
                    }
                    
                    $last_row_data = mysqli_fetch_assoc($result); //"last" because i will use it on each iteration
                    $md5 = $last_row_data['checksum_all'];
                    
                    
                    #
                    #   get actual master's dummy pos
                    #
                    $master_n = dummy_getn( $master_link );                    
                    
                    
                    #
                    #   wait for slaves to catch'up master's dummy pos
                    #
                    for(;;)
                    {
                        foreach( $slave_links as $k => $link )
                        {
                            // get slave's status
                            $result = mysqli_query($link, "SHOW SLAVE STATUS;");
                            
                            if( mysqli_errno( $link ) != 0 )
                            {
                                echo __LINE__ . ' ' . mysqli_error($link);
                                exit();
                            }                               
                            
                            if( $result->num_rows == 0 )
                            {
                                echo "Cant retrieve SLAVE (#{$k}) status";
                                exit();
                            }                                  
                            
                            $slave_status = mysqli_fetch_assoc($result);                            
                            
                            // check for errors on slaves (if replication error occurs during checksum - there is no way to continue it properly)
                            if( $slave_status['Last_SQL_Errno'] != 0 )
                            {
                                echo "Slave (#$k) - REPLICATION ERROR (".$slave_status['Last_SQL_Error'].") - Cant continue...";
                                exit();
                            }                            
                            
                            // get slave's pos
                            $slave_n = dummy_getn( $link );
                            
                            // werify
                            $slave_n_ok = true;
                            
                            if( ! ($slave_n >= $master_n) )
                            {
                                $slave_n_ok = false;
                            }
                        }
                        
                        if( $slave_n_ok == true )
                        {
                            break;
                        }
                        else
                        {
                            usleep(100);
                        }
                    }
                    
                    
                    #
                    #   checksum on each slave
                    #
                    $slave_md5 = [];
                    foreach( $slave_links as $k => $link )
                    {
                        $result = mysqli_query($link, $query);

                        if( ! $result instanceof mysqli_result )
                        {
                            echo __LINE__ . ' ' . mysqli_error($link);
                            exit();
                        }        

                        if( $result->num_rows == 0 )
                        {
                            break;
                        }         
                        
                        $slave_data = mysqli_fetch_assoc($result);
                        $slave_md5[$k] = $slave_data['checksum_all'];
                    }
                    
                    
                    #
                    #   release lock on master
                    #
                    mysqli_query($master_link, "COMMIT");
                    if( mysqli_errno( $master_link ) != 0 )
                    {
                        echo __LINE__ . ' ' . mysqli_error($master_link);
                        exit();
                    }                    
                    
                    
                    #
                    #   compare
                    #
                    $md5_checksum_result = true;
                    $slave_md5_compare_result = [];
                    foreach( $slave_links as $k => $link )
                    {
                        if( $md5 != $slave_md5[$k] )
                        {
                            $slave_md5_compare_result[$k] = false;
                            $md5_checksum_result = false;
                            $integrity_result = false;
                            $integrity_result_bad_tables[$table['TABLE_NAME']] = 1;
                        }
                        else
                        {
                            $slave_md5_compare_result[$k] = true;
                        }
                    }
                    
                    // elapsed time for chunk
                    $time_chunk_end = getmicrotime();
                    $time_chunk_elapsed = $time_chunk_end - $time_chunk_start;
                    
                    // ++estimated rows processed
                    $rows_processed+=$chunk_size;
                    
                    // info
                    if( $md5_checksum_result == true )
                    {
                        $info_md5 = substr($md5, 0, 8) . "... : OK";
                    }
                    else
                    {
                        $info_md5 = "#master:" . substr($md5, 0, 8) . "...";
                        foreach( $slave_links as $k => $link )
                        {
                            $info_md5 .= ", #{$k}:" . substr($slave_md5[$k], 0, 8) . "...";
                        }
                        $info_md5 .= ' : INTEGRITY ERROR!';
                    }
                    
                    echo $table['TABLE_NAME'] . ' : ' . $table['alg'] . ' : ' . implode(',',$table['key_column_names']) . ' : '. $rows_processed . ' : ' . round($time_chunk_elapsed,1) . 's : ' . $info_md5 . "\r\n";
                    
                    // master_log++ 
                    dummy_increment();
                    
                    // set "non-first" (it modifes query statment)
                    $first=false;
                }
                break;
                
            case 'MYSQL_CHECKSUM':
                $time_chunk_start = getmicrotime();
                
                #
                #   begin transaction (to hold lock until slave catches'up master's log pos)
                #
                mysqli_query($master_link, "START TRANSACTION");
                if( mysqli_errno( $master_link ) != 0 )
                {
                    echo __LINE__ . ' ' . mysqli_error($master_link);
                    exit();
                }

                
                #
                #   lock all tables for edit
                #
                $query = "SELECT 1 FROM (SELECT * FROM `".$table['TABLE_NAME']."` FOR UPDATE) T LIMIT 1;";
                mysqli_query($master_link, $query);
                if( mysqli_errno( $master_link ) != 0 )
                {
                    echo __LINE__ . ' ' . mysqli_error($master_link);
                    exit();
                }                
                
                
                #
                #   get actual master's log pos
                #
                $master_n = dummy_getn( $master_link );


                #
                #   check on master
                #
                $query = "CHECKSUM TABLE `{$table['TABLE_NAME']}`";
                $result = mysqli_query($master_link, $query);

                if( ! $result instanceof mysqli_result )
                {
                    echo __LINE__ . ' ' . mysqli_error($master_link);
                    exit();
                }        

                if( $result->num_rows == 0 )
                {
                    break;
                }

                $last_row_data = mysqli_fetch_assoc($result); //"last" because i will use it on each iteration
                $md5 = $last_row_data['Checksum'];

                
                #
                #   wait for slaves to catch'up master's dummy pos
                #
                for(;;)
                {
                    foreach( $slave_links as $k => $link )
                    {
                        // get slave's status
                        $result = mysqli_query($link, "SHOW SLAVE STATUS;");

                        if( mysqli_errno( $link ) != 0 )
                        {
                            echo __LINE__ . ' ' . mysqli_error($link);
                            exit();
                        }                               

                        if( $result->num_rows == 0 )
                        {
                            echo "Cant retrieve SLAVE (#{$k}) status";
                            exit();
                        }                                  

                        $slave_status = mysqli_fetch_assoc($result);                            

                        // check for errors on slaves (if replication error occurs during checksum - there is no way to continue it properly)
                        if( $slave_status['Last_SQL_Errno'] != 0 )
                        {
                            echo "Slave (#$k) - REPLICATION ERROR (".$slave_status['Last_SQL_Error'].") - Cant continue...";
                            exit();
                        }                            

                        // get slave's pos
                        $slave_n = dummy_getn( $link );

                        // werify
                        $slave_n_ok = true;

                        if( ! ($slave_n >= $master_n) )
                        {
                            $slave_n_ok = false;
                        }
                    }

                    if( $slave_n_ok == true )
                    {
                        break;
                    }
                    else
                    {
                        usleep(100);
                    }
                }


                #
                #   checksum on each slave
                #
                $slave_md5 = [];
                foreach( $slave_links as $k => $link )
                {
                    $result = mysqli_query($link, $query);

                    if( ! $result instanceof mysqli_result )
                    {
                        echo __LINE__ . ' ' . mysqli_error($link);
                        exit();
                    }        

                    if( $result->num_rows == 0 )
                    {
                        break;
                    }         

                    $slave_data = mysqli_fetch_assoc($result);
                    $slave_md5[$k] = $slave_data['Checksum'];
                }


                #
                #   release lock on master
                #
                mysqli_query($master_link, "COMMIT");
                if( mysqli_errno( $master_link ) != 0 )
                {
                    echo __LINE__ . ' ' . mysqli_error($master_link);
                    exit();
                }                    


                #
                #   compare
                #
                $md5_checksum_result = true;
                $slave_md5_compare_result = [];
                foreach( $slave_links as $k => $link )
                {
                    if( $md5 != $slave_md5[$k] )
                    {
                        $slave_md5_compare_result[$k] = false;
                        $md5_checksum_result = false;
                        $integrity_result = false;
                        $integrity_result_bad_tables[$table['TABLE_NAME']] = 1;
                    }
                    else
                    {
                        $slave_md5_compare_result[$k] = true;
                    }
                }                

                // elapsed time for table
                $time_chunk_end = getmicrotime();
                $time_chunk_elapsed = $time_chunk_end - $time_chunk_start;                
                
                // info
                if( $md5_checksum_result == true )
                {
                    $info_md5 = substr($md5, 0, 8) . "... : OK";
                }
                else
                {
                    $info_md5 = "#master:" . substr($md5, 0, 8) . "...";
                    foreach( $slave_links as $k => $link )
                    {
                        $info_md5 .= ", #{$k}:" . substr($slave_md5[$k], 0, 8) . "...";
                    }
                    $info_md5 .= ' : INTEGRITY ERROR!';
                }

                echo $table['TABLE_NAME'] . ' : ' . $table['alg'] . ' : ' . round($time_chunk_elapsed,1) . 's : ' . $info_md5 . "\r\n";

                // master_log++ 
                dummy_increment();
                
                break;
            
            default:
                echo $table['TABLE_NAME'] . ' : ' . 'Error - Couldnt determine algorithm for checksum.' . "\r\n";
                exit();
                break;
        }
    }
    
    
    #
    #   clean-up
    #
    $query = "DROP TABLE __integrity_check_dummy__;";
    $result = mysqli_query($master_link, $query);
    if( mysqli_errno( $master_link ) != 0 )
    {
        echo __LINE__ . ' ' . mysqli_error($master_link);
        exit();
    }   
    
    $time_end = getmicrotime();
    $time_elapsed = $time_end - $time_start;
    
    
    #
    #   result
    #
    echo "\r\n";
    
    if( $integrity_result == true)
    {
        echo "Integrity result: ALL OK";
    }
    else
    {
        echo "Integrity result: INTEGRITY ERROR IN TABLES: ".implode(', ', array_keys($integrity_result_bad_tables));
    }
    echo "\r\n";
    echo "Check finished in ".round($time_elapsed,1)." seconds.";
    
    echo "\r\n";