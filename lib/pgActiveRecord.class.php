<?php
class ActiveRecord
{
  private static $db_attributes = array();
  private static $db_host = "localhost";
  private static $db_name = "cigstories";
  private static $db_connection;
  private static $db_clauses = array();

  #- interface
  public function __construct($options = null){
    self::initialise();
    self::initialise_an_instance($this);
    if($options){
      $this->append($options);
    }
    return $this;
  }

  public static function __callStatic($func, $argv){
    $columns = self::parse_magic_query($func);
    $class = get_called_class();
    $idx = 0;
    foreach($columns as $column){
      $class = $class::where(array($column => $argv[$idx]));
      $idx = $idx + 1;
    }
    return $class::first();
  }

  public function commit(){
    $class = get_class($this);
    return $this->new_record() ?
      $class::db_insert($this) :
      $class::db_update($this);
  }

  public static function find_by_sql($query){
    self::initialise();
    $ret = self::db_find_by_sql($query);
    self::db_clear_clauses();
    return $ret;
  }

  public static function db_attributes(){
    return self::$db_attributes[self::table_name()];
  }

  public function append($attributes){
    foreach($attributes as $attribute => $value){
      $this->$attribute = $value;
    };
  }

  public static function where($clause){
    self::db_where($clause);
    return get_called_class();
  }

  public static function all(){
    $clauses= self::db_clauses();
    $query = self::sql_sexify($clauses);
    $select_clause = "select * from " . self::table_name();
    if($query){
      $query = array($query);
      array_unshift($query, $select_clause);
      $query = implode($query, ' where ');
    }
    $ret = array();
    $query_result = self::find_by_sql($query ? $query : $select_clause);
    foreach($query_result as $db_row){
      $ret[] = self::instantiate_row($db_row);
    };
    return $ret;
  }

  public static function first(){
    $clauses= self::db_clauses();
    $query = self::sql_sexify($clauses);
    $select_clause = "select * from " . self::table_name();
    if($query){
      $query = array($query);
      array_unshift($query, $select_clause);
      $query = implode($query, ' where ');
    }
    $final_query = ($query ? $query : $select_clause)  . " LIMIT 1; ";
    $res = array_pop(self::find_by_sql($query));
    return $res ? self::instantiate_row($res) : NULL;
  }

  public static function db_clauses(){
    if(isset(self::$db_clauses[self::table_name()])){
      return self::$db_clauses[self::table_name()];
    };
    return null;
  }

  #- implementation

  private function new_record(){
    return ($this->id == null);
  }

  private static function value_for_column($column){
    return null;
  }

  private static function db_find_by_sql($query = null){
    return pg_fetch_all(pg_query($query));
  }

  private static function db_where($clause){
    self::add_where_clause($clause);
  }

  private static function db_clear_clauses(){
    self::$db_clauses[self::table_name()] = array();
    return true;
  }

  private static function add_where_clause($clause){
    if ( !isset( self::$db_clauses[self::table_name()] ) || !is_array(self::$db_clauses[self::table_name()]) ){ self::$db_clauses[self::table_name()] = array(); }
    $table_clauses= self::db_clauses();
    $table_clauses[]= self::format_where_clause($clause);
    self::$db_clauses[self::table_name()] = $table_clauses;
  }

  private static function sql_sexify($queries){
    return sizeof($queries) > 0 ? implode($queries, ' and ') : null;
  }

  private static function is_valid_attribute($attribute){
    return array_key_exists(array_values(self::db_attributes()), $attribute);
  }

  private static function table_name_and_attribute($attrib){
    $wrap_in_quotes = function($entity){ return "\"$entity\""; };
    $names_with_post = array_map($wrap_in_quotes, array(self::table_name(), $attrib));
    return implode($names_with_post, '.');
  }

  private static function initialised(){
    return array_key_exists(self::table_name(), self::$db_attributes) && self::$db_connection;
  }

  private static function not_initialised(){
    return !self::initialised();
  }

  private static function table_name(){
    return strtolower(pluralize(classify(get_called_class())));
  }

  private static function no_db_connection(){
    return !(self::$db_connection);
  }

  private static function initialise_an_instance($instance){
    foreach(self::db_attributes() as $attribute){
      $instance->$attribute = self::value_for_column($attribute);
    }
    return $instance;
  }

  private static function initialise(){
    if(self::initialised()){ return true; }
    self::establish_connection();
    self::fetch_schema();
  }

  private static function establish_connection(){
    if(self::no_db_connection()){
      self::$db_connection = pg_connect(self::options_for_pg_connect());
      return true;
    }
    return true;
  }

  private static function fetch_schema(){
    $schema_query = 'select column_name from INFORMATION_SCHEMA.COLUMNS where table_name =\'' . self::table_name() . '\'';
    $columns = self::db_find_by_sql($schema_query);
    $filter_column_name = function($column){ return $column['column_name']; };
    return self::$db_attributes[self::table_name()] = array_map( $filter_column_name, $columns);
  }

  private static function options_for_pg_connect(){
    $join_columns = function($arg, $val){return "$arg=$val";};
    $db_columns = array(
      'dbname' => self::$db_name,
      'port'   => null,
      'user'   => 'dev',
      'password' => null
    );
    $db_columns = array_filter($db_columns, 'strlen');
    return implode(array_map($join_columns, array_keys($db_columns), array_values($db_columns)), ' ');
  }

  private static function format_where_clause($clause){
    if (! is_array($clause) ){
      return $clause;
    }
    foreach($clause as $attrib => $value){
      return implode(array(self::table_name_and_attribute($attrib), "'$value'"), '= ');
    }
  }

  private static function instantiate_row($db_row){
    $object = get_called_class();
    return new $object($db_row);
  }

  private static function db_columns(){
    return array_values(self::$db_attributes[self::table_name()]);
  }

  private static function db_insert($instance){
    $instance->before_commit();
    $attributes = array();
    foreach($instance as $attribute => $value){
      if($attribute == "id"){ continue; }
      $attributes[$attribute] = $value;
    }
    return pg_insert(self::$db_connection, self::table_name(), $attributes);
  }


  private static function db_update($instance){
  }

  private function before_commit(){
    $class = get_class($this);
    if(isset($class::$before_commit)){
      foreach((array)$class::$before_commit as $bc_callback){
        $this->{$bc_callback}();
      }
    } else {
      return true;
    }
  }

  private static function after_commit(){
  }

  private static function parse_magic_query($func_name){
    $soft_words = array('find', 'by', 'and','or');
    $column_splat = explode('_', $func_name);
    return array_diff($column_splat, $soft_words);
  }
}
?>
