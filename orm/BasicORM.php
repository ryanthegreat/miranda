<?php
namespace miranda\ORM;

use miranda\database\PDOEngine;
use miranda\cache\CacheFactory;
use miranda\exceptions\GeneralException;
use PDO;

class BasicORM
{
    protected static $table_name	= NULL;
    protected static $primary_key	= NULL;
    protected static $column_map	= array();
    protected static $accessible	= true;
    protected $persisted		= false;
    protected $values			= array();
    protected $updated			= false;
    protected $cacheable		= false;
    protected $cache_expire		= 0;
    protected $foreign_key		= NULL;
    
    protected function __construct() {} /** Should not be instantiated directly */
    
    public function __get($key)
    {
	if(array_key_exists($key, static::$column_map))
	{
	    $key = static::$column_map[$key];
	}
	
	if(!is_array(static::$accessible) || array_key_exists($key, static::$accessible))
	    return $this -> values[$key];
	else
	    throw new GeneralException("Property: '$key' is inaccessible.");
    }
    
    public function __set($key, $value)
    {
	if(array_key_exists($key, static::$column_map))
	{
	    $key = static::$column_map[$key];
	}
		
	$this -> updated[]	= $key;
	$this -> values[$key]	= $value;
    }    
    
    public function mapsTo($table_name)
    {
	static::$table_name = $table_name;
    }
    
    public function hasColumn($column_name, $column_map)
    {
	static::$column_map[$column_name] = $column_map;
    }
    
    public function hasRelationship($relationship_alias, $relationship_class, $foreign_key)
    {
	$this -> values[$relationship_alias] = new $relationship_class;
	$this -> values[$relationship_alias] -> $foreign_key = isset($this -> values[static::$column_map[static::$primary_key]]) ? $this -> values[static::$column_map[static::$primary_key]] : 0;
	$this -> values[$relationship_alias] -> setForeignKey($foreign_key);
    }
    
    public function accessible($key)
    {
	if(is_array(static::$accessible))
	{
	    static::$accessible[$key] = true;
	}
	else
	{
	    static::$accessible = array();
	    static::$accessible[$key] = true;
	}
    }
    
    public function setForeignKey($foreign_key)
    {
	$this -> foreign_key = $foreign_key;
    }
	
    public function isCacheable($expireTime)
    {
	$this -> cacheable	= true;
	$this -> cache_expire	= (int) intval($expireTime);
    }
    
    public function clearUpdated()
    {
	$this -> updated = array();
    }
    
    public function getNew()
    {
	$class = get_called_class();
		
	$new = new $class;
	if(!empty($this -> foreign_key) && !empty($this -> values[$class::$column_map[$this -> foreign_key]]))
	{
	    $foreign_key = $this -> foreign_key;
	    $new -> setForeignKey($foreign_key);
	    $new -> $foreign_key = $this -> values[$class::$column_map[$this -> foreign_key]];
	}
		
	return $new;
    }
    
    protected function update($force = false)
    {
	$class	= get_called_class();
	$db	= PDOEngine::getInstance();
	$query	= 'UPDATE `' . $class::$table_name .'` SET ';
	$values	= array();
		
	if(!is_array($this -> updated) && !$force)
	{
	    return false;
	}
	else if(is_array($this -> updated))
	{
	    foreach($this -> updated as $key)
	    {
		$query	= $query . $class::$column_map[$key] . ' = ?, ';
		$values[]	= is_array($this -> values[$class::$column_map[$key]]) ? serialize($this -> values[$class::$column_map[$key]]) : $this -> values[$class::$column_map[$key]];
	    }
	}
	else
	{
	    foreach($class::$column_map as $map_key => $value_key)
	    {
		$query	.= '?, ';
		$values[]	= isset($this -> values[$value_key]) ? is_array($this -> values[$value_key]) ? serialize($this -> values[$value_key]) : $this -> values[$value_key] : '';
	    }
	}
	
	
			
	$query = substr($query, 0, -2);
	$query .= ' WHERE `' . $class::$primary_key . "` = '{$this -> values[$class::$column_map[$class::$primary_key]]}'";
	
	$stmt = $db -> prepare($query);
	$stmt -> execute($values);
	$stmt -> closeCursor();
		
	$this -> updated = false;
    }
		
    protected function insert()
    {
	$class = get_called_class();
	if(!isset($this -> values[self::$primary_key])) $this -> values[self::$primary_key] = 0;
		
	$db	= PDOEngine::getInstance();
	$query	= 'INSERT INTO `' . $class::$table_name .'` ( ' . implode(array_keys($class::$column_map), ',') . ') VALUES (';
	$values	= array();
		
	foreach($class::$column_map as $map_key => $value_key)
	{
	    $query	.= '?, ';
	    $values[]	= isset($this -> values[$value_key]) ? is_array($this -> values[$value_key]) ? serialize($this -> values[$value_key]) : $this -> values[$value_key] : '';
	}
	
	$query	= substr($query, 0, -2) . ')';
	$stmt	= $db -> prepare($query);
	$stmt -> execute($values);
	$this -> values[$class::$primary_key] = $db -> lastInsertId();
	$stmt -> closeCursor();
    }
    
    public static function findOne($pkey = 0)
    {
	$class	= get_called_class();
		
	$class::setup();
		
	$cache = CacheFactory::getInstance(CACHE_DEFAULT);
	if($object = $cache -> get($class::$table_name . '_' . $pkey)) return $object;

	$db	= PDOEngine::getInstance();
	$query	= "SELECT ";
	$keys 	= implode(',', array_keys($class::$column_map));
		
	$query .= $keys . ' FROM `' . $class::$table_name . '` ';
		
	if($pkey)
	{
	    $query .= 'WHERE `' . $class::$primary_key . '` = :pkey ';
	}
		
	$query .= 'LIMIT 1';
	$stmt = $db -> prepare($query);
		
	if($pkey)
	    $stmt -> bindParam(':pkey', $pkey);
	
	$stmt -> execute();
	
	    
	$stmt -> setFetchMode(PDO::FETCH_CLASS, $class);
	$object = $stmt -> fetch();
	
	if(!$object)
	    return false;
	    
	$object -> persisted = true;
	    
	$object -> clearUpdated();
	$stmt -> closeCursor();
	
	return $object;
    }
    
    public function find($limit = 0, $order_by = '', $sort_order = 'ASC')
    {
	$class = get_called_class();
		
	if(!isset($this -> foreign_key) || !isset($this -> values[$class::$column_map[$this -> foreign_key]]) || !($foreign_key_value = $this -> values[$class::$column_map[$this -> foreign_key]]))
	{
	    return false;
	}
		
	$db		= PDOEngine::getInstance();
	$query	= "SELECT ";
	$keys 	= implode(',', array_keys($class::$column_map));
		
	$query .= $keys . ' FROM `' . $class::$table_name . '` ';
		
	$query .= 'WHERE `'. $this -> foreign_key .'` = :fkey';
		
	if($limit)
	    $query .= ' LIMIT :limit ';
			
		
			
	if(!empty($order_by) && isset($class::$column_map[$order_by]))
	{
	    if($sort_order != 'ASC')
	    {
		$sort_order = 'DESC';
	    }
			
	    $query .= " ORDER BY `$order_by` $sort_order";
	}		
		
	$stmt = $db -> prepare($query);
		
	$stmt -> bindParam(':fkey', $foreign_key_value);
		
	if($limit)
	{
	    $stmt -> bindParam(':limit', $limit, PDO::PARAM_INT);
	}
		
	$found	= array();
	$object	= new $class;
		
	$stmt -> execute();
	$stmt -> setFetchMode(PDO::FETCH_CLASS, $class);
		
	while($object = $stmt -> fetch())
	{
	    $object -> clearUpdated();
	    $found[] = $object;
	}
		
	$stmt -> closeCursor();
		
	return count($found) > 1 ? $found : $found[0];
    }
    
    public static function findBy($key = '', $value = '', $limit = 0, $order_by = '', $sort_order = 'ASC')
    {
	$class	= get_called_class();
		
	$class::setup();
		
	$db	= PDOEngine::getInstance();
	$query	= "SELECT ";
	$keys 	= implode(',', array_keys($class::$column_map));
		
	$query .= $keys . ' FROM `' . $class::$table_name . '` ';
		
	if(!empty($key) && !empty($value) && in_array($key, array_keys($class::$column_map)))
	    $query .= "WHERE `$key` = :value";
		
	if($limit) $query .= ' LIMIT :limit ';
			
	if(!empty($order_by) && isset($class::$column_map[$order_by]))
	{
	    if($sort_order != 'ASC')
	    {
		$sort_order = 'DESC';
	    }
			
	    $query .= " ORDER BY `$order_by` $sort_order";
	}
	$stmt = $db -> prepare($query);	
		
	if(!empty($key) && !empty($value))
	{
	    $stmt -> bindParam(':value', $value);
	}
	if($limit)
	{
	    $stmt -> bindParam(':limit', $limit, PDO::PARAM_INT);
	}
		
	$found = array();
	$object	= new $class;
	$stmt -> execute();
	$stmt -> setFetchMode(PDO::FETCH_CLASS, $class);
		
	while($object = $stmt -> fetch())
	{
	    $object -> clearUpdated();
	    $found[] = $object;
	}
		
	$stmt -> closeCursor();
		
	return count($found) > 1 ? $found : $found[0];
    }
    
    public static function where($where, $values)
    {
	$class = get_called_class();
	$class::setup();
		
		
	$db	= PDOEngine::getInstance();
	$query	= "SELECT ";
	$keys 	= implode(',', array_keys($class::$column_map));
		
	$query .= $keys . ' FROM `' . $class::$table_name . '` ';
		
	$query .= "WHERE $where";
		
	$stmt = $db -> prepare($query);
	$stmt -> execute($values);
		
	$found = array();
	$object	= new $class;
	$stmt -> execute();
	$stmt -> setFetchMode(PDO::FETCH_CLASS, $class);
		
	while($object = $stmt -> fetch())
	{
	    $object -> clearUpdated();
	    $found[] = $object;
	}
		
	$stmt -> closeCursor();
		
	return count($found) > 1 ? $found : $found[0];
    }
		
    public function save($force = false)
    {
	if(!$this -> updated) return false;
			
	if($this -> persisted)
	{
	    $this -> update($force);
	}
	else
	{
	    $this -> insert();
	}
		
	$this -> cache();
    }
	
    public function cache()
    {
	if(!$this -> cacheable) return false;
		
	$cache = CacheFactory::getInstance(CACHE_DEFAULT);
	$cache -> set('models_' . static::$table_name . '_' . $this -> values[static::$column_map[static::$primary_key]], $this, $this -> cache_expire);
    }
}
?>