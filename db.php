<?php

/**
 * db.php
 *
 * Copyright (c) 2010-2012 Brad Proctor. (http://bradleyproctor.com)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @author      Brad Proctor
 * @copyright   Copyright (c) 2010-2012 Brad Proctor
 * @license     MIT License (http://www.opensource.org/licenses/mit-license.php)
 * @link        http://bradleyproctor.com/
 * @version     1.4
 */
class Database_Exception extends \FuelException
{

}

/**
 * Controls the DB instances
 */
abstract class DB
{

	/**
	 * Creates a new instance of the database class.
	 *
	 * @param string $name
	 *		The name of the instance to create
	 */
	final public static function instance($name = 'default')
	{
		static $instances = array();
		if (isset ($instances[$name]) and $instances[$name] instanceof DB_Driver) {
			return $instances[$name];
		}
		$instances[$name] = new DB_Driver();
		return $instances[$name];
	}
}

/**
 * The main database driver
 */
class DB_Driver
{

	private $read_conn = null;         // The read connection
	private $write_conn = null;        // The write connection
	private $last_result = null;       // The last query result
	private $last_error = null;        // The last error
	private $sql = null;               // Last query
	private $master_on_write = true;   // If true, chooses to use the master for read queries once a write occurs

	/**
	 * Create the DB object
	 */

	public function __construct()
	{
		\Config::load('db', true);
	}

	/**
	 * Connect to a database
	 *
	 * @param string $type
	 * 		Set to 'read' for a read connection, 'write' for a write connection
	 *
	 * @return bool
	 * 		Returns TRUE on success, FALSE on error
	 */
	public function connect($type = 'read')
	{
		try {
			$config = \Config::get('db.' . \Config::get('db.active'));
			$num_dbs = count($config['servers']);

			// Use the first connection, if this is a write connection, or there is only one server to use
			if ($type == 'write' || ($type == 'read' && $num_dbs == 1)) {
				$host = $config['servers'][0]['hostname'];
				$user = $config['servers'][0]['username'];
				$pass = $config['servers'][0]['password'];
				$name = $config['servers'][0]['database'];
				$port = $config['servers'][0]['port'];
				$char = $config['servers'][0]['charset'];
			} else if ($type == 'read') {
				// Choose a random read server
				$i = rand(1, $num_dbs - 1);
				$host = $config['servers'][$i]['hostname'];
				$user = $config['servers'][$i]['username'];
				$pass = $config['servers'][$i]['password'];
				$name = $config['servers'][$i]['database'];
				$port = $config['servers'][$i]['port'];
				$char = $config['servers'][$i]['charset'];
			} else {
				throw new Database_Exception('Invalid connection type selected', 0);
			}

			$conn = new mysqli($host, $user, $pass, $name, $port);
			if ($conn->error) {
				throw new Database_Exception($conn->error, $conn->errno);
			}

			if (! empty($char) && $conn->set_charset($char) === false) {
				throw new Database_Exception($conn->error, $conn->errno);
			}
		} catch (ErrorException $e) {
			throw new Database_Exception('No MySQLi Connection: ' . $e->getMessage(), 0);
		}

		// If there is only one database, set both read and write so we don't end up with two connections to the same server
		if ($num_dbs == 1) {
			$this->write_conn = $this->read_conn = $conn;
		} else {
			($type === 'read') ? $this->read_conn = $conn : $this->write_conn = $conn;
		}

		return $conn->error;
	}

	/**
	 * Destroys this object and closes the database connection
	 *
	 * @return bool
	 *    Returns the FALSE if the database failed to close, TRUE on success
	 */
	public function __destruct()
	{
		return $this->close();
	}

	/**
	 * Close the database connection
	 *
	 * @return bool
	 *    Returns the FALSE if the database failed to close, TRUE on success
	 */
	public function close($type = null)
	{
		if ($type == 'read') {
			if ($this->read_conn instanceof mysqli) {
				return $this->read_conn->close();
			}
		} else if ($type == 'write') {
			if ($this->write_conn instanceof mysqli) {
				return $this->write_conn->close();
			}
		} else {
			if ($this->read_conn instanceof mysqli) {
				return $this->read_conn->close();
			}
			if ($this->write_conn instanceof mysqli) {
				return $this->write_conn->close();
			}
		}
		return true;
	}

	/**
	 * Returns the last error
	 *
	 * @return string
	 *    Returns the last error
	 */
	public function error()
	{
		return $this->last_error;
	}

	/**
	 * Free the memory from the last results
	 *
	 * @return bool
	 * 		Returns TRUE if the result was successfully freed, FALSE on error
	 */
	public function free()
	{
		if ($this->last_result instanceof mysqli_result) {
			$this->last_result->free();
			return true;
		}
		return false;
	}

	/**
	 * Returns the last inserted ID
	 *
	 * @return int|bool
	 *    Returns the last insert ID, or FALSE if no insert ID
	 */
	public function insertId()
	{
		if ($this->write_conn instanceof mysqli) {
			return $this->write_conn->insert_id;
		}
		return false;
	}

	/**
	 * Retuns the number of rows from the last query
	 *
	 * @return int|bool
	 *    Return the number of rows the last query, or FALSE
	 */
	public function rows()
	{
		if ($this->last_result instanceof mysqli_result) {
			return $this->last_result->num_rows;
		}
		return false;
	}

	/**
	 * Returns the number of affected rows from the last query
	 *
	 * @return int|bool
	 * 		Return the number of affected rows from last query, or FALSE
	 */
	public function affectedRows()
	{
		if ($this->last_result instanceof mysqli_result) {
			return $this->last_result->affected_rows;
		}
		return false;
	}

	/**
	 * Returns the last sql executed
	 *
	 * @return string
	 *    Returns the last sql executed
	 */
	public function lastQuery()
	{
		return $this->sql;
	}

	/**
	 * Begin a new transaction
	 */
	public function begin()
	{
		if (!($this->write_conn instanceof mysqli)) {
			$this->connect('write');
		}
		$this->write_conn->autocommit(false);
	}

	/**
	 * Commit the current transaction
	 */
	public function commit()
	{
		if (!($this->write_conn instanceof mysqli)) {
			$this->connect('write');
		}

		$this->write_conn->commit();
		$this->write_conn->autocommit(true);
	}

	/**
	 * Rollback a transaction
	 */
	public function rollback()
	{
		if (!($this->write_conn instanceof mysqli)) {
			$this->connect('write');
		}

		$this->write_conn->rollback();
		$this->write_conn->autocommit(true);
	}

	/**
	 * Queries the database and returns an object of the results
	 * All other database query functions come here
	 *
	 * @param string $str
	 *    The query string to execute
	 *
	 * @return object|bool
	 *    Returns the results mysqli object, or FALSE if there is an error
	 */
	public function query()
	{

		$args = func_get_args();

		// Determine if this is a read or write request
		$write = strncasecmp(trim($args[0]), 'SELECT', 6) !== 0;

		// Set up $conn to the right connection
		if ($write === true) {
			if (! ($this->write_conn instanceof mysqli)) {
				if ($this->connect('write') === false) {
					return false;
				}
			}
			$conn = $this->write_conn;
		} else {
			// If this is a read, but we already have a write connection
			// We use write anyway, because once the first write has been done, all queries need to go through the master
			// To help avoid select after insert replication problems
			if ($this->master_on_write and $this->write_conn instanceof mysqli) {
				$conn = $this->write_conn;
			} else if ($this->read_conn instanceof mysql) {
				$conn = $this->read_conn;
			} else {
				if ($this->connect('read') === false) {
					return false;
				}
				$conn = $this->read_conn;
			}
		}

		// Set up the rest of the parameters
		$count = count($args);
		for ($i = 1; $i < $count; $i++) {
			$args[$i] = addcslashes($write ? $conn->escape_string($args[$i]) : $conn->escape_string($args[$i]), '%_');
		}
		$this->sql = array_shift($args);
		if ($count > 1) {
			$this->sql = vsprintf($this->sql, $args);
		}

		// Free the last result
		if ($this->last_result instanceof mysqli_result) {
			$this->last_result->free();
		}

		// Perform the query
		$this->last_result = $conn->query($this->sql);
		if ($this->last_result === false) {
			throw new Database_Exception($conn->error . ' [ ' . $this->sql . ' ]', $conn->errno);
			return false;
		}
		return $this->last_result;
	}

	/**
	 * Performs a REPLACE query
	 *
	 * @param string $str
	 *    The SQL query to execute
	 *
	 * @return object|bool
	 *    Returns the mysqli results, or FALSE on error
	 */
	public function replace()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		return $this->last_result;
	}

	/**
	 * Performs an INSERT query
	 *
	 * @param string $str
	 *    The SQL query to execute
	 *
	 * @return int|bool
	 *    Returns the insert ID, or FALSE on error
	 */
	public function insert()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		return $this->write_conn->insert_id;
	}

	/**
	 * Perform an INSERT query with an array
	 *
	 * @param string $table
	 *		The table to insert into
	 *
	 * @param array $data
	 *		The data to insert, associative array
	 *
	 * @return int|bool
	 *		Returns the insert ID, or FALSE on error
	 */
	public function insertArray($table, array $data)
	{
		$s1 = $s2 = '';
		foreach ($data as $k => $v) {
			$s1 .= ' `' . $k . '`, ';
			$s2 .= '"' . $v . '", ';
		}
		$sql = 'INSERT INTO `' . $table .'` (' . substr($s1, 0, -2) . ') VALUES (' . substr($s2, 0, -2) . ')';
		if ($this->query($sql) === false) {
			return false;
		}
		return $this->write_conn->insert_id;
	}

	/**
	 * Performs an UPDATE query
	 *
	 * @param string $str
	 *    The SQL query to execute
	 *
	 * @return int|bool
	 *    Returns the number of rows updated, or FALSE on error
	 */
	public function update()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		return $this->write_conn->affected_rows;
	}

	/**
	 * Perform an UPDATE query
	 *
	 * @param string $table
	 *		Name of the table to update
	 *
	 * @param array $data
	 *		The data to update
	 *
	 * @param string $sql
	 *		(Optional) Additional sql appended to end such as a WHERE clause
	 *
	 * @return int|bool
	 *		Returns the number of affected rows, or FALSE on error
	 */
	public function updateArray($table, array $data, $sql = null)
	{
		$args = func_get_args();
		array_shift($args);
		array_shift($args);
		$sql = array_shift($args);
		$str = '';
		foreach ($data as $k => $v) {
			$str .= '`'.$k.'` = "'.$v.'", ';
		}
		$str = 'UPDATE `' . $table . '` SET ' . substr($str, 0, -2) . ' ' . $sql;
		array_unshift($args, $str);
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		return $this->write_conn->affected_rows;
	}

	/**
	 * Perform a DELETE query
	 *
	 * @param string $str
	 *    The SQL statement to execute
	 *
	 * @return int|bool
	 *    Returns the number of rows updated, or FALSE on error
	 */
	public function delete()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		return $this->write_conn->affected_rows;
	}

	/**
	 * Queries the database and returns an array of the results
	 *
	 * @param string $str
	 *    The query string to execute
	 *
	 * @return array|bool
	 *    The results array or FALSE if there was an error
	 */
	public function select()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		$members = array();
		while ($member = $this->last_result->fetch_assoc()) {
			$members[] = $member;
		}
		return $members;
	}

	/**
	 * Performs a SELECT query
	 *
	 * @param string $str
	 *    The SQL query to execute
	 *
	 * @return object|bool
	 *    Returns the mysqli results as an object, or FALSE on error
	 */
	public function selectObject()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		return $this->last_result;
	}

	/**
	 * Queries the database and returns multiple rows as a flat array.  This is useful if you
	 * want a single value from multiple rows.
	 *
	 * @param string $str
	 * 		The query string to execute
	 *
	 * @return array|bool
	 * 		The results array or FALSE if there was an error
	 */
	public function selectFlat()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		$flat = array();
		while ($member = $this->last_result->fetch_row()) {
			foreach ($member as $k => $v) {
				$flat[] = $v;
			}
		}
		return $flat;
	}

	/**
	 * Queries the database and returns a single row array of results
	 *
	 * @param string $str
	 *    The query string to execute
	 *
	 * @return array|bool
	 *    The results array or FALSE if there was an error
	 */
	public function selectRow()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		return $this->last_result->fetch_assoc();
	}

	/**
	 * Queries the database and returns a single value result
	 *
	 * @param string $str
	 *    The query string to execute
	 *
	 * @return mixed
	 *    The result or FALSE if there was an error
	 */
	public function selectValue()
	{
		$args = func_get_args();
		if (call_user_func_array(array('DB_Driver', 'query'), $args) === false) {
			return false;
		}
		$value = $this->last_result->fetch_row();
		return $value[0] ? : false;
	}

	/**
	 * Get the server status string
	 *
	 * @param string $conn
	 *		The connection type, either read or write.  Defaults to read.
	 *
	 * @return string|bool
	 *		The server status string, or FALSE on error
	 */
	public function stat($conn = 'read')
	{
		if ($conn == 'read') {
			return ($this->read_conn instanceof mysqli) ? $this->read_conn->stat() : false;
		} else if ($conn == 'write') {
			return ($this->write_conn instanceof mysqli) ? $this->write_conn->stat() : false;
		}
		return false;
	}

	/**
	 * Get the server version number
	 * The form of this version number is main_version * 10000 + minor_version * 100 + sub_version
	 * (i.e. version 4.1.0 is 40100).
	 *
	 * @param string $conn
	 *		The connection type, either read or write.  Defaults to read.
	 *
	 * @return string|bool
	 *		The server version, or false on error
	 */
	public function serverVersion($conn = 'read')
	{
		if ($conn == 'read') {
			return ($this->read_conn instanceof mysqli) ? $this->read_conn->server_version : false;
		} else if ($conn == 'write') {
			return ($this->write_conn instanceof mysqli) ? $this->write_conn->server_version : false;
		}
		return false;
	}

}
