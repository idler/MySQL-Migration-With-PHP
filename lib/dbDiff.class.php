<?php

class dbDiff
{

  /**
   *
   * @var mysqli main database connection
   */
  protected $current;
  /**
   *
   * @var mysqli temp database connection
   */
  protected $published;
  /**
   *
   * @var array
   */
  protected $difference = array('up' => array(), 'down' => array());
  
  protected function up($sql)
  {
    if(!strlen($sql)) return;
    $this->difference['up'][] = $sql;
  }
  
  protected function down($sql)
  {
    if(!strlen($sql)) return;
    $this->difference['down'][] = $sql;
  }
  
  public function __construct($currentDbVersion, $lastPublishedDbVersion)
  {
    $this->current = $currentDbVersion;
    $this->published = $lastPublishedDbVersion;
  }
  
  public function getDifference()
  {
    $current_tables = $this->getTables($this->current);
    $published_tables = $this->getTables($this->published);
    sort($current_tables);
    sort($published_tables);
    $this->createFullTableDifference($current_tables, $published_tables);

    $common = array_intersect($current_tables, $published_tables);
    $this->createDifferenceBetweenTables($common);
    return $this->difference;
  }
  
  protected function createFullTableDifference($current_tables, $published_tables)
  {

    sort($current_tables);
    sort($published_tables);

    $create = array_diff($current_tables, $published_tables);
    $drop = array_diff($published_tables, $current_tables);
    foreach ($create as $table) $this->addCreateTable($table, $this->current);
    foreach ($drop as $table) $this->addDropTable($table, $this->published);
  }
  
  protected function getTables($db)
  {
    $res = $db->query('show tables');
    $tables = array();
    while ($row = $res->fetch_array(MYSQLI_NUM))
    {
      $tables[] = $row[0];
    }
    return $tables;
  }
  
  protected function addCreateTable($tname, $db)
  {
    $this->down($this->dropTable($tname));
    $this->up($this->dropTable($tname));
    $this->up(Helper::getSqlForTableCreation($tname, $db));
  }
  
  protected function addDropTable($tname, $db)
  {
    $this->up($this->dropTable($tname));
    $this->down($this->dropTable($tname));
    $this->down(Helper::getSqlForTableCreation($tname, $db));
  }
  
  protected function createDifferenceBetweenTables($tables)
  {
    foreach ($tables as $table)
    {
      $query = "DESCRIBE `{$table}`";
      $table_current_columns = $this->getColumnList($this->current->query($query));
      $table_published_columns = $this->getColumnList($this->published->query($query));
      $this->createDifferenceInsideTable($table, $table_current_columns, $table_published_columns);
      $this->createIndexDifference($table);
    }
  }
  
  protected function getColumnList($result)
  {
    $columns = array();
    while ($row = $result->fetch_assoc())
    {
      unset($row['Key']);
      $columns[] = $row;
    }
    return $columns;
  }
  
  protected function createDifferenceInsideTable($table, $table_current_columns, $table_published_columns)
  {

    foreach ($table_current_columns as $current_column)
    {
      $column_for_compare = $this->checkColumnExists($current_column, $table_published_columns);

      if (!$column_for_compare)
      {
        $this->up($this->addColumn($table, $current_column));
        $this->down($this->dropColumn($table, $current_column));
      }
      else
      {
        if ($current_column === $column_for_compare) continue;
        $sql = $this->changeColumn($table, $current_column);
        $this->up($sql);
        $sql = $this->changeColumn($table, $column_for_compare);
        $this->down($sql);
      }
    }


    foreach ($table_published_columns as $published_column)
    {

      $has = $this->checkColumnExists($published_column, $table_current_columns);

      if (!$has)
      {
        $constraint = $this->getConstraintForColumn($this->published, $table, $published_column['Field']);
        //echo "COLUMNS\n\n"; var_dump($constraint);
        if(count($constraint))
        {
          $this->down($this->addConstraint(array('constraint'=>$constraint)));
          $this->up($this->dropConstraint(array('constraint'=>$constraint)));
        }
        $this->down($this->addColumn($table, $published_column));
        $this->up($this->dropColumn($table, $published_column));
      }
    }
  }
  
  protected function addSqlExtras( & $sql, $column)
  {
    if ($column['Null'] === 'NO') $sql .= " not null ";
    if (!is_null($column['Default'])) $sql .= " default \\'{$column['Default']}\\' ";
  }
  
  protected function changeColumn($table, $column)
  {
    $sql = "ALTER TABLE `{$table}` CHANGE " .
      " `{$column['Field']}` `{$column['Field']}` " .
      " {$column['Type']} ";
    $this->addSqlExtras($sql, $column);
    return $sql;
  }
  
  protected function addColumn($table, $column)
  {
    $sql = "ALTER TABLE `{$table}` ADD `{$column['Field']}` {$column['Type']} ";
    $this->addSqlExtras($sql, $column);
    return $sql;
  }
  
  protected function dropColumn($table, $column)
  {
    return "ALTER TABLE `{$table}` DROP {$column['Field']}";
  }
  
  protected function dropTable($t)
  {
    return "DROP TABLE IF EXISTS `{$t}`";
  }
  
  protected function checkColumnExists($column, $column_list)
  {
    foreach ($column_list as $compare_column)
    {
      if ($compare_column['Field'] === $column['Field'])
      {
        return $compare_column;
      }
    }
    return false;
  }
  
  protected function createIndexDifference($table)
  {
    $current_indexes   = $this->getIndexListFromTable($table, $this->current);
    $published_indexes = $this->getIndexListFromTable($table, $this->published);

    foreach ($current_indexes as $cur_index)
    {
      $index_for_compare = $this->checkIndexExists($cur_index, $published_indexes);
      if (!$index_for_compare)
      {
        $this->down($this->dropConstraint($cur_index));
        $this->down($this->dropIndex($cur_index));
        $this->up($this->dropConstraint($cur_index));
        $this->up($this->dropIndex($cur_index));
        $this->up($this->addIndex($cur_index));
        $this->up($this->addConstraint($cur_index));
      }
      elseif($index_for_compare === $cur_index)
      {
        continue;
      }
      else // index exists but not identical
      {
        $this->down($this->dropConstraint($cur_index));
        $this->down($this->dropIndex($cur_index));
        $this->down($this->addIndex($index_for_compare));
        $this->down($this->addConstraint($index_for_compare));
        $this->up($this->dropConstraint($cur_index));
        $this->up($this->dropIndex($cur_index));
        $this->up($this->addIndex($cur_index));
        $this->up($this->addConstraint($cur_index));
      }
    }
  }
  
  protected function getIndexListFromTable($table, mysqli $connection)
  {
    $sql = "SHOW INDEXES FROM `{$table}`";
    $res = $connection->query($sql);
    $indexes = array();
    while ($row = $res->fetch_array(MYSQLI_ASSOC))
    {
      if (!isset($indexes[$row['Key_name']])) $indexes[$row['Key_name']] = array();
      $indexes[$row['Key_name']]['unique'] = !intval($row['Non_unique']);
      $indexes[$row['Key_name']]['type'] = $row['Index_type'];
      $indexes[$row['Key_name']]['name'] = $row['Key_name'];
      $indexes[$row['Key_name']]['table'] = $row['Table'];
      if (!isset($indexes[$row['Key_name']]['fields'])) $indexes[$row['Key_name']]['fields'] = array();
      $indexes[$row['Key_name']]['fields'][$row['Seq_in_index']] =
        array(
        'name' => $row['Column_name'],
        'length' => $row['Sub_part']
      );
      $indexes[$row['Key_name']]['constraint']  = $this->getConstraintForColumn($connection,$table,$row['Column_name']);

    }
    //var_dump($indexes);
    return $indexes;
  }
  
  protected function checkIndexExists($index, $index_list)
  {
    foreach($index_list as $comparing_index)
    {
      if($index['name']===$comparing_index['name'])
      {
        return $comparing_index;
      }
    }
    return false;
  }
  
  protected function addIndex($index)
  {
    if($index['name'] === 'PRIMARY'){
       $index_string = "ALTER TABLE `{$index['table']}` ADD PRIMARY KEY";
       $fields = array();
       foreach ($index['fields'] as $f) 
       {
         $len = intval($f['length']) ? "({$f['length']})" : '';
         $fields[] = "{$f['name']}" . $len;
       }
       $index_string .= "(" . implode(',', $fields) . ")";
     }else{
       $index_string = "CREATE ";
       if ($index['type'] === 'FULLTEXT') $index_string .= " FULLTEXT ";
       if ($index['unique']) $index_string .= " UNIQUE ";
       $index_string .= " INDEX `{$index['name']}` ";
       if (in_array($index['type'], array('RTREE', 'BTREE', 'HASH', ))) 
       {
         $index_string .= " USING {$index['type']} ";
       }
       $index_string .= " on `{$index['table']}` ";
       $fields = array();
       foreach ($index['fields'] as $f) 
       {
         $len = intval($f['length']) ? "({$f['length']})" : '';
         $fields[] = "{$f['name']}" . $len;
       }
       $index_string .= "(" . implode(',', $fields) . ")";
    }
    return $index_string;
  }

  protected function dropIndex($index)
  {
    return "DROP INDEX `{$index['name']}` on `{$index['table']}`";
  }

  protected function getConstraintForColumn(mysqli $connection,$table,$col_name)
  {
    $q = "select database() as dbname";
    $res = $connection->query($q);
    $row = $res->fetch_array(MYSQLI_ASSOC);
    $dbname = $row['dbname'];
    Helper::verbose("DATABASE: {$row['dbname']}");

    $sql = "SELECT k.CONSTRAINT_SCHEMA,k.CONSTRAINT_NAME,k.TABLE_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.key_column_usage k LEFT JOIN information_schema.referential_constraints r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND k.TABLE_NAME=r.TABLE_NAME AND k.REFERENCED_TABLE_NAME=r.REFERENCED_TABLE_NAME LEFT JOIN information_schema.table_constraints t ON t.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND t.TABLE_NAME=r.TABLE_NAME WHERE k.constraint_schema='$dbname' AND t.CONSTRAINT_TYPE='FOREIGN KEY' AND k.TABLE_NAME='$table' AND k.COLUMN_NAME='$col_name'";
    Helper::verbose($sql);
    $res = $connection->query($sql);
    $row = $res->fetch_array(MYSQLI_ASSOC);

    if(!count($row)) return false;

    $constraint = array(
        'table'       => $table,
        'name'       => $row['CONSTRAINT_NAME'],
        'column'     => $row['COLUMN_NAME'],
        'reference'  => array(
            'table'  => $row['REFERENCED_TABLE_NAME'],
            'column' => $row['REFERENCED_COLUMN_NAME'],
            'update' => $row['UPDATE_RULE'],
            'delete' => $row['DELETE_RULE'],
        )
    );
    //echo "=================\n\n\n\=========";
    //var_dump($constraint);
    return $constraint;
  }

  protected function dropConstraint($index)
  {
    if(!isset($index['constraint']['column']) || !strlen($index['constraint']['column'])) return '';
    $sql = "ALTER TABLE `{$index['constraint']['table']}` ".
      "DROP FOREIGN KEY `{$index['constraint']['name']}` ";

    //echo  "DELETE==================================\n$sql\n";
    //var_dump($index['constraint']);
    return $sql;
  }

  protected function addConstraint($index)
  {
    if(!isset($index['constraint']['column']) || !strlen($index['constraint']['column'])) return '';
    $sql = "ALTER TABLE `{$index['constraint']['table']}` ".
      "ADD CONSTRAINT `{$index['constraint']['name']}` ".
      "FOREIGN KEY (`{$index['constraint']['column']}`) ".
      "REFERENCES `{$index['constraint']['reference']['table']}` ".
      "(`{$index['constraint']['reference']['column']}`) ".
      "on update {$index['constraint']['reference']['update']} ".
      "on delete {$index['constraint']['reference']['delete']} ";
    //echo  "ADD==================================\n$sql\n\n";
    //var_dump($index['constraint']);
    return $sql;
  }

}

