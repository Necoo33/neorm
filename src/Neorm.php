<?php

namespace Necdet\Neorm;

use PDO;
use Exception;

 /** Simple ORM (Object-Relational Mapping) query builder class.
 *
 * Provides a fluent interface to construct and execute SQL queries dynamically.
 * Supports SELECT, INSERT, UPDATE, DELETE queries with methods for conditions,
 * joins, ordering, grouping, transactions, and custom SQL fragments.
 *
 * Features:
 * - Chainable methods for building complex queries step-by-step.
 * - Automatic parameter binding to prevent SQL injection.
 * - Transaction management with beginTransaction, commit, and rollback.
 * - Flexible condition methods supporting expressions, IN/NOT IN clauses, and parentheses grouping.
 * - Supports raw SQL injection through appendCustom and completeCustom methods.
 *
 * Usage example:
 * ```php
 * $orm = new ORM($pdoConnection);
 * $results = $orm->select(['id', 'name'])
 *                ->table('users')
 *                ->where('status', '=', 'active')
 *                ->orderBy('created_at', 'desc')
 *                ->limit(10)
 *                ->execute()
 *                ->result();
 * ```
 * @package    Neorm
 * @author     Necdet
 * @license    MIT License
 * @version    2.2.0
 * @link       https://github.com/Necoo33/neorm
 */
class Neorm {
    public $query = "";
    public $params = [];
    public $table = "";
    public $connection;
    public $recentAction;
    public $transactionStarted = false;

    public function __construct($host, $name, $pass, $db, $port = 3306)
    {
        $this->connection = new PDO("mysql:host=$host;dbname=$db;port=$port", $name, $pass);
        $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        if(!$this->connection) {
            throw new Exception("cannot connect to database");
        }
    }

    /**
     * Starts a new database transaction.
     *
     * Throws an exception if a transaction has already been started and not yet committed or rolled back.
     *
     * @throws Exception If a transaction is already in progress.
     *
     * @return void
     */
    public function beginTransaction(){
        if($this->transactionStarted){
            throw new Exception("Transaction already started");
        }

        $this->transactionStarted = true;
        $this->connection->beginTransaction();
    }

    /**
     * Commits the current database transaction.
     *
     * Throws an exception if no transaction has been started.
     *
     * @throws Exception If there is no active transaction to commit.
     *
     * @return void
     */
    public function commit(){
        if(!$this->transactionStarted){
            throw new Exception("Transaction not started");
        }

        $this->connection->commit();
        $this->transactionStarted = false;
    }

    /**
     * Rolls back the current database transaction.
     *
     * Throws an exception if no transaction has been started.
     *
     * @throws Exception If there is no active transaction to roll back.
     *
     * @return void
     */
    public function rollback(){
        if(!$this->transactionStarted){
            throw new Exception("Transaction not started");
        }

        $this->connection->rollBack();
        $this->transactionStarted = false;
    }

    /**
     * Starts building a SELECT query.
     *
     * Accepts either a single string "*" to select all columns,
     * or an array of column names to select specific fields.
     *
     * Throws an exception if the query is already started and not finished,
     * or if a string argument other than "*" is provided.
     *
     * @param string|array $fields Either "*" as string or an array of column names.
     *
     * @throws Exception If a query is already in progress or
     *                   if string argument is not "*".
     *
     * @return static Returns the current instance for method chaining.
     */
    public function select(string|array $fields){
        if(!$this->restartable()) {
            throw new Exception("You cannot start to build new query with same instance if you don't finish current one");
        } 

        switch (gettype($fields)) {
            case "string":
                if($fields !== "*") {
                    throw new Exception("If select function's argument will be the string, it has to be '*'");
                }

                $this->query = "SELECT * FROM ";
                break;
            case "array":
                $this->query = "SELECT ";

                $getCount = count($fields);

                for($i = 0; $i < $getCount; $i++){
                    $sanitizedField = $fields[$i];

                    if($i + 1 === $getCount) {
                        $this->query = $this->query."$sanitizedField ";  
                    } else {
                        $this->query = $this->query."$sanitizedField, ";  
                    }
                }

                $this->query = $this->query."FROM";
        }       

        return $this;
    }

    /**
     * Adds a LIMIT clause to the query.
     *
     * Accepts either an integer or numeric string representing the number of rows to limit.
     * Throws an exception if the provided limit is not numeric.
     *
     * @param int|string $limit The maximum number of rows to return.
     *
     * @throws Exception If the limit parameter is not numeric.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function limit(string|int $limit) {
        if(is_numeric($limit)) {
            $limit = intval($limit);
        } else {
            throw new Exception("Limit parameter has to be an integer");
        }

        $this->query = $this->query." LIMIT $limit";

        return $this;
    }

    /**
     * Checks if the current query builder instance can start a new query.
     *
     * Returns false if a query (SELECT, INSERT, DELETE, UPDATE) has already been started
     * and not yet finished (i.e., query does not end with a semicolon).
     * Returns true otherwise, indicating it's safe to start a new query.
     *
     * @return bool True if new query can be started; false if a query is in progress.
     */
    function restartable(){
        if((strpos($this->query, "SELECT") === 0 ||
            strpos($this->query, "INSERT") === 0 ||
            strpos($this->query, "DELETE") === 0 ||
            strpos($this->query, "UPDATE") === 0) &&
            substr($this->query, -1) !== ";") {
                return false;
        } else {
            return true;
        }
    }

    /**
     * Adds an OFFSET clause to the query.
     *
     * Accepts either an integer or numeric string representing the number of rows to skip.
     * Throws an exception if the provided offset is not numeric.
     *
     * @param int|string $offset The number of rows to skip.
     *
     * @throws Exception If the offset parameter is not numeric.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function offset(string|int $offset) {
        if(is_numeric($offset)) {
            $offset = intval($offset);
        } else {
            throw new Exception("Offset parameter has to be an integer");
        }

        $this->query = $this->query." OFFSET $offset";

        return $this;
    }

    /**
     * Appends a WHERE condition to the query.
     *
     * This method supports a variety of value types including string, number, boolean, and null.
     * It automatically translates `null` values to `IS NULL` or `IS NOT NULL` conditions depending on the operator.
     * Also supports SQL constants or functions if detected via `checkIfItsFunctionOrConstant()`.
     *
     * @param string $column The column name to apply the condition to.
     * @param string $mark The comparison operator (e.g. '=', '!=', '>', '<', etc.).
     * @param mixed $value The value to compare against. Can be string, int, float, bool, or null.
     *
     * @throws Exception If the provided value is of an unsupported type.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function where(string $column, string $mark, $value) {
        switch(gettype($value)) {
            case "string": 
            case "integer":
            case "double":
            case "float":
            case "NULL":
                break;
            case "boolean":
                if($value === "true") {
                    $value = true;
                } else {
                    $value = false;
                }
                break;
            default:
                throw new Exception("Invalid input for value input");
                break;
        }

        $newChunk = "";

        if(!str_ends_with($this->query, "(")){
            $newChunk = " WHERE ";
        }

        if(gettype($value) === "NULL" || strtolower($value) === "null"){
            switch($mark) {
                case "=":
                    $this->query = $this->query . $newChunk . "$column IS NULL";
                    break;
                case "!=":
                    $this->query = $this->query . $newChunk . "$column IS NOT NULL";
                    break;
            }
        } else {
            if($this->checkIfItsFunctionOrConstant($value)) {
                $this->query = $this->query . $newChunk . "$column $mark $value";
            } else {
                $this->query = $this->query . $newChunk . "$column $mark ?";
                $this->params[] = $value;
            }
        }

        return $this;
    }

    /**
     * Appends a raw SQL expression to the WHERE clause of the query.
     *
     * This method allows injecting manual SQL expressions directly into the WHERE condition,
     * without parameter binding or sanitization. Use it for advanced use-cases such as
     * `column = NOW()` or `column = OTHER_COLUMN`, but be cautious about SQL injection risks.
     *
     * @param string $column The column name (currently unused, kept for consistency or future use).
     * @param string $mark The SQL operator to use (e.g. '=', '!=', '<', '>', etc.).
     * @param string $value The raw SQL value or expression to append. It will be injected as-is.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function whereExpr(string $column, string $mark, string $value){
        $newChunk = "";

        if(!str_ends_with($this->query, "(")){
            $newChunk = " WHERE ";
        }

        $this->query .= $newChunk . " $column $mark $value";

        return $this;
    }

    /**
     * Appends a SQL JOIN clause to the query.
     *
     * Supported join types are: INNER, LEFT, RIGHT, CROSS, and NATURAL. The type is case-insensitive
     * and automatically uppercased internally. If an invalid type is given, it defaults to INNER JOIN.
     *
     * For INNER, LEFT, and RIGHT joins, the `$left`, `$mark`, and `$right` parameters are required
     * to build the ON condition (e.g. `users.id = posts.user_id`).
     *
     * For CROSS and NATURAL joins, no ON clause is added.
     *
     * Example usage:
     * `$builder->join('left', 'posts', 'users.id', '=', 'posts.user_id');`
     *
     * @param string $type The type of JOIN. Supported values: "inner", "left", "right", "cross", "natural".
     * @param string $table The name of the table to join with.
     * @param string|null $left The left-hand side of the ON condition (e.g. `users.id`). Required for INNER, LEFT, and RIGHT joins.
     * @param string|null $mark The comparison operator (e.g. `=`, `!=`, etc.). Required for INNER, LEFT, and RIGHT joins.
     * @param string|null $right The right-hand side of the ON condition (e.g. `posts.user_id`). Required for INNER, LEFT, and RIGHT joins.
     *
     * @throws Exception If a required ON condition component is missing for INNER, LEFT, or RIGHT joins.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function join(string $type, string $table, ?string $left = null, ?string $mark = null, ?string $right = null) {
        $joinType = strtoupper($type);

        switch($joinType) {
            case "INNER":
            case "LEFT":
            case "RIGHT":
            case "CROSS":
            case "NATURAL":
                break;
            default:
                $joinType = "INNER";
                break;
        }

        switch($joinType) {
            case "INNER":
            case "LEFT":
            case "RIGHT":
                if($left === null || $mark === null || $right === null) {
                    throw new Exception("Left, mark and right parameters are required for $joinType join query.");
                }

                $this->query = $this->query." $joinType JOIN $table ON $left $mark $right";
                break;
            case "CROSS":
            case "NATURAL":
                $this->query = $this->query." $joinType JOIN $table";
                break;
            default:
                throw new Exception("Invalid join type");
                break;
        }

        return $this;
    }

    /**
     * Adds an IN condition with a logical connector (WHERE, AND, OR) to the query.
     *
     * The `$mode` parameter specifies how this condition connects to the previous ones:
     * - "WHERE": starts the WHERE clause
     * - "AND": adds an AND condition
     * - "OR": adds an OR condition
     *
     * The `$column` specifies the column to filter on,
     * and `$values` is the array of values to match within the IN clause.
     *
     * All values are bound as parameters to prevent SQL injection.
     *
     * Example usage:
     * ```php
     * $builder->in('WHERE', 'status', ['active', 'pending']);
     * $builder->in('AND', 'id', [1, 2, 3]);
     * $builder->in('OR', 'category', [10, 20]);
     * ```
     *
     * @param string $mode The logical connector, e.g., "WHERE", "AND", "OR" (case-insensitive).
     * @param string $column The column name to apply the IN condition to.
     * @param array $values The array of values for the IN condition.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function in(string $mode, string $column, array $values) {
        $keys = array_keys($values);
        $values = array_values($values);

        $valuesString = "";

        for($i = 0; $i < count($values); $i++) {
            if($i === 0) {
                $valuesString = $valuesString."?";
                $this->params[] = $values[$i];
            } else {
                $valuesString = $valuesString.", ?";
                $this->params[] = $values[$i];
            }
        }

        $mode = strtoupper($mode);

        $this->query = $this->query." $mode $column IN ($valuesString)";

        return $this;
    }

    /**
     * Adds a NOT IN condition with a logical connector (WHERE, AND, OR) to the query.
     *
     * The `$mode` parameter specifies how this condition connects to previous conditions:
     * - "WHERE": starts the WHERE clause
     * - "AND": adds an AND condition
     * - "OR": adds an OR condition
     *
     * The `$column` is the column to filter on,
     * and `$values` is the array of values to exclude from the column.
     *
     * All values are bound as parameters to prevent SQL injection.
     *
     * Example usage:
     * ```php
     * $builder->notIn('WHERE', 'status', ['inactive', 'banned']);
     * $builder->notIn('AND', 'id', [10, 20, 30]);
     * ```
     *
     * @param string $mode The logical connector, e.g., "WHERE", "AND", "OR" (case-insensitive).
     * @param string $column The column name to apply the NOT IN condition on.
     * @param array $values The array of values to exclude.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function notIn(string $mode, string $column, array $values) {
        $valuesString = "";

        for($i = 0; $i < count($values); $i++) {
            if($i === 0) {
                $valuesString = $valuesString."?";
                $this->params[] = $values[$i];
            } else {
                $valuesString = $valuesString.", ?";
                $this->params[] = $values[$i];
            }
        }

        $mode = strtoupper($mode);

        $this->query = $this->query." $mode $column NOT IN ($valuesString)";

        return $this;
    }

    /**
     * Appends an OR condition to the query.
     *
     * Supports various value types: string, int, float, null, and boolean.
     * Null values are translated to `IS NULL` or `IS NOT NULL` SQL expressions depending on the operator.
     * Also supports raw SQL expressions or constants detected by `checkIfItsFunctionOrConstant()`.
     *
     * The condition is prefixed with `OR` unless the query ends with '('.
     *
     * @param string $column The column name for the condition.
     * @param string $mark The comparison operator (e.g. '=', '!=', '<>', etc.).
     * @param mixed $value The value to compare against. Supports string, int, float, bool, or null.
     *
     * @throws Exception If the value type is unsupported.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function or(string $column, string $mark, $value) {
        switch(gettype($value)) {
            case "string": 
            case "integer":
            case "double":
            case "NULL":
            case "float":
                break;
            case "boolean":
                if($value === "true") {
                    $value = true;
                } else {
                    $value = false;
                }
                break;
            default:
                throw new Exception("Invalid input for value input");
                break;
        }

        $newChunk = "";

        if(!str_ends_with($this->query, "(")){
            $newChunk = " OR ";
        }

        if(gettype($value) === "NULL" || strtolower($value) === "null"){
            switch($mark) {
                case "=":
                    $this->query = $this->query . $newChunk . "$column IS NULL";
                    break;
                case "!=":
                case "<>":
                    $this->query = $this->query . $newChunk . "$column IS NOT NULL";
                    break;
            }
        } else {
            if($this->checkIfItsFunctionOrConstant($value))  {
                $this->query = $this->query . $newChunk . "$column $mark $value";
            } else {
                $this->query = $this->query . $newChunk . "$column $mark ?";
                $this->params[] = $value;
            }
        }

        return $this;
    }

    /**
     * Appends a raw SQL expression prefixed by OR to the query.
     *
     * This method inserts the provided SQL expression directly into the query
     * without parameter binding or sanitization.
     * Use with caution to avoid SQL injection vulnerabilities.
     *
     * The expression is prefixed with "OR" unless the query ends with "(".
     *
     * @param string $column The column name used in the expression (for clarity; not sanitized).
     * @param string $mark The SQL operator (e.g., '=', '!=', '<', '>', etc.).
     * @param string $value The raw SQL expression or value to append.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function orExpr(string $column, string $mark, string $value){
        $newChunk = "";

        if(!str_ends_with($this->query, "(")){
            $newChunk = " OR ";
        }

        $this->query .= $newChunk . " $column $mark $value";

        return $this;
    }

    /**
     * Appends an AND condition to the query.
     *
     * Supports various value types including string, int, float, null, and boolean.
     * Null values are converted to `IS NULL` or `IS NOT NULL` based on the operator.
     * Detects raw SQL functions or constants via `checkIfItsFunctionOrConstant()` and injects them as-is.
     *
     * The condition is prefixed with `AND` unless the query ends with '('.
     *
     * @param string $column The column name for the condition.
     * @param string $mark The comparison operator (e.g. '=', '!=', '<>', etc.).
     * @param mixed $value The value to compare against. Supports string, int, float, bool, or null.
     *
     * @throws Exception If the value type is unsupported.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function and(string $column, string $mark, $value){
        switch(gettype($value)) {
            case "string": 
            case "integer":
            case "double":
            case "float":
            case "NULL":
                break;
            case "boolean":
                if($value === "true") {
                    $value = true;
                } else {
                    $value = false;
                }
                break;
            default:
                throw new Exception("Invalid input for value input");
                break;
        }

        $newChunk = "";

        if(!str_ends_with($this->query, "(")){
            $newChunk = " AND ";
        }

        if(gettype($value) === "NULL" || strtolower($value) === "null"){
            switch($mark) {
                case "=":
                    $this->query = $this->query . $newChunk . "$column IS NULL";
                    break;
                case "!=":
                case "<>":
                    $this->query = $this->query . $newChunk . "$column IS NOT NULL";
                    break;
            }
        } else {
            if($this->checkIfItsFunctionOrConstant($value)) {
                $this->query = $this->query . $newChunk . "$column $mark $value";
            } else {
                $this->query = $this->query . $newChunk . "$column $mark ?";
                $this->params[] = $value;
            }
        }

        return $this;
    }

    /**
     * Appends a raw SQL expression prefixed by AND to the query.
     *
     * Inserts the given SQL snippet directly without parameter binding or sanitization.
     * Use with caution to avoid SQL injection vulnerabilities.
     *
     * The expression is prefixed with "AND" unless the query ends with "(".
     *
     * @param string $column The column name used in the expression (for clarity; not sanitized).
     * @param string $mark The SQL operator (e.g. '=', '!=', '<', '>', etc.).
     * @param string $value The raw SQL expression or value to append.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function andExpr(string $column, string $mark, string $value){
        $newChunk = "";

        if(!str_ends_with($this->query, "(")){
            $newChunk = " AND ";
        }

        $this->query .= $newChunk . " $column $mark $value";

        return $this;
    }

    /**
     * Opens a parenthesis in the query, prefixed by a logical connector.
     *
     * Supported types are "WHERE", "AND", and "OR" (case-insensitive).
     * This is useful for grouping conditions within parentheses in complex queries.
     *
     * Example:
     * ```php
     * $builder->open_parenthesis('WHERE')
     *         ->and('age', '>', 18)
     *         ->or('status', '=', 'active')
     *         ->close_parenthesis();
     * ```
     *
     * @param string $type The logical connector to prefix the parenthesis. Must be one of "WHERE", "AND", or "OR".
     *
     * @throws Exception If an unsupported type is given.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function open_parenthesis(string $type){
        $upper = trim(strtoupper($type));

        switch($upper) {
            case "WHERE":
            case "AND":
            case "OR":
                break;
            default:
                throw new Exception("Supported types are 'WHERE', 'AND', and 'OR' (case-insensitive), error!");
                break;
        }

        $this->query = $this->query . " $upper (";

        return $this;
    }

    /**
     * Closes the most recently opened parenthesis in the query.
     *
     * Throws an exception if no open parenthesis exists.
     *
     * Example:
     * ```php
     * $builder->open_parenthesis('WHERE')
     *         ->and('age', '>', 18)
     *         ->close_parenthesis();
     * ```
     *
     * @throws Exception If there is no open parenthesis to close.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function close_parenthesis(){
        if(strrpos($this->query, "(") === false) {
            throw new Exception("You cannot close any parenthesis if there is not opened.");
        }

        $this->query = $this->query . ")";

        return $this;
    }

    /**
     * Adds a LIKE condition for multiple columns connected with OR.
     *
     * This method applies the LIKE condition to each column in the `$columns` array,
     * connecting them with OR clauses, searching for the given `$operand` substring.
     *
     * It only supports queries starting with SELECT, DELETE, or UPDATE.
     *
     * All LIKE conditions use parameter binding with wildcards (%) around the operand.
     *
     * Example:
     * ```php
     * $builder->like(['name', 'description'], 'apple');
     * // Adds: WHERE name LIKE '%apple%' OR description LIKE '%apple%'
     * ```
     *
     * @param string[] $columns An array of column names to apply the LIKE condition on.
     * @param string $operand The substring to search for within the columns.
     *
     * @throws Exception If the query is not SELECT, DELETE, or UPDATE.
     * @throws Exception If any column name is not a string.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function like(array $columns, string $operand) {
        if(strpos($this->query, "SELECT") !== 0 &&
           strpos($this->query, "DELETE") !== 0 &&
           strpos($this->query, "UPDATE") !== 0){
            throw new Exception("LIKE conditions should only applied to SELECT, DELETE or UPDATE queries, error!");
        }

        for($i = 0; $i < count($columns); $i ++) {
            if(gettype($columns[$i]) !== "string"){
                throw new Exception("All columns must be string, error!");
            }
            
            if($i === 0) {
                $this->query = $this->query." WHERE $columns[$i] LIKE ?";
                $this->params[] = "%$operand%";
            } else {
                $this->query = $this->query." OR $columns[$i] LIKE ?";
                $this->params[] = "%$operand%";
            }
        }

        return $this;
    }

    /**
     * Adds an ORDER BY clause to the SQL query.
     *
     * Supports ordering by a specified column in ascending (ASC) or descending (DESC) order,
     * as well as random ordering via "RAND()". If the `$column` is null, empty, or
     * one of "rand", "RAND", "random", "RANDOM", the query will be ordered randomly.
     *
     * If an ORDER BY clause already exists in the query, this method appends the new ordering.
     * Throws an exception if both random ordering and ordered sorting (ASC/DESC) are used together.
     *
     * @param string|null $column   The column name to order by. If null or a recognized random keyword, orders randomly.
     * @param string|null $ordering The ordering direction: "ASC" or "DESC" (case-insensitive). Defaults to random if invalid or not specified.
     *
     * @throws Exception If the ordering option is invalid or if conflicting orderings are used.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function orderBy(?string $column = null, ?string $ordering = null){
        switch($column){
            case null:
            case "":
            case "rand":
            case "RAND":
            case "random":
            case "RANDOM":
                $this->query = $this->query." ORDER BY RAND()";
                return $this;
        }

        if(!is_null($ordering)){
            $ordering = strtoupper($ordering);
        }

        switch($ordering) {
            case "ASC":
            case "DESC":
                break;
            case "rand":
            case "RAND":
            case "random":
            case "RANDOM":
            case "":
            case null:
                $ordering = "RAND()";
                break;

            default:
                throw new Exception("Ordering option has to be either asc or desc.");
        }

        if(strpos($this->query, "ORDER BY") !== false){
            if($ordering === "RAND()" && ((strpos($this->query, "ASC") !== false) || (strpos($this->query, "DESC") !== false))){
                throw new Exception("You cannot order rows both random and asc or desc.");
            } else {
                $this->query = $this->query.", $column $ordering";
            }
        } else {
            if($ordering === "RAND()") {
                $this->query = $this->query." ORDER BY $ordering";
            } else {
                $this->query = $this->query." ORDER BY $column $ordering";
            }
        }

        return $this;
    }

    /**
     * Adds an ORDER BY FIELD clause to the query to order by specific values in a given order.
     *
     * Uses MySQL FIELD() function to order rows by the given `$fields` sequence.
     * If an ORDER BY clause already exists, this appends the FIELD ordering.
     *
     * Example:
     * ```php
     * $builder->orderByField('status', ['pending', 'processing', 'completed']);
     * ```
     *
     * @param string $column The column name to order by.
     * @param string[] $fields The array of field values defining the custom order.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function orderByField(string $column, array $fields) {
        if(strpos($this->query, "ORDER BY") !== false) {
            $fieldsString = "";

            for($i = 0; $i < count($fields); $i++) {
                if($i === 0) {
                    $field = $fields[$i];
                    $fieldsString = $fieldsString."'$field'";
                } else {
                    $field = $fields[$i];
                    $fieldsString = $fieldsString.", '$field'";
                }
            }

            $this->query = $this->query.", FIELD($column, $fieldsString)";
        } else {
            $fieldsString = "";

            for($i = 0; $i < count($fields); $i++) {
                if($i === 0) {
                    $field = $fields[$i];
                    $fieldsString = $fieldsString."'$field'";
                } else {
                    $field = $fields[$i];
                    $fieldsString = $fieldsString.", '$field'";
                }
            }
            
            $this->query = $this->query." ORDER BY FIELD($column, $fieldsString)";
        }

        return $this;
    }

    /**
     * Adds a GROUP BY clause to the query.
     *
     * If a GROUP BY clause already exists, the new column is appended to it.
     * Otherwise, a new GROUP BY clause is created.
     *
     * Example:
     * ```php
     * $builder->groupBy('category_id');
     * ```
     *
     * @param string $column The column name to group by.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function groupBy(string $column) {
        if (stripos($this->query, "GROUP BY") !== false) {
            $this->query .= ", $column";
        } else {
            $this->query .= " GROUP BY $column";
        }

        return $this;
    }


   /**
     * Starts building an INSERT INTO query for the current table.
     *
     * Takes an associative array where keys are column names and values are the values to insert.
     * If a value is detected as a SQL function or constant (e.g., NOW()), it will be embedded directly into the query.
     * Otherwise, a parameterized placeholder (?) will be used and the value will be added to the internal parameter list.
     *
     * Example:
     * ```php
     * $builder->table("users")->insert([
     *     "name" => "John",
     *     "created_at" => "NOW()"
     * ]);
     * ```
     *
     * @param array<string, mixed> $insertObject An associative array of column => value pairs to insert.
     *
     * @throws Exception If a query is already being built on this instance or if the input is empty.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function insert(array $insertObject) {
        if(!$this->restartable()) {
            throw new Exception("You cannot start to build new query with same instance if you don't finish current one");
        } 

        $keys = array_keys($insertObject);
        $values = array_values($insertObject);

        $keysLength = count($keys);
        $valuesLength = count($values);

        $this->query = "INSERT INTO ".$this->table." (";

        for($i = 0; $i < $keysLength; $i ++) {
            if($i + 1 === $keysLength) {
                $this->query = $this->query.$keys[$i].")";   
            } else {
                $this->query = $this->query.$keys[$i].", ";
            }
        }

        $this->query = $this->query." VALUES (";

        for($p = 0; $p < $valuesLength; $p++){
            $value = $values[$p];

            if($p + 1 === $valuesLength) {
                if($this->checkIfItsFunctionOrConstant($value)) {
                    $this->query = $this->query."$value);";
                } else {
                    $this->query = $this->query."?);";
                    $this->params[] = $value;
                }
            } else {
                if($this->checkIfItsFunctionOrConstant($value)) {
                    $this->query = $this->query."$value, ";
                } else {
                    $this->query = $this->query."?, ";
                    $this->params[] = $value;
                }
            }
        }

        return $this;
    }

    /**
     * Starts building an UPDATE query.
     *
     * Initializes the internal SQL query string with the "UPDATE" statement.
     * Before doing so, it checks whether a previous query is already in progress
     * on the same instance using {@see restartable()}. If a previous query exists and is not finished,
     * an exception is thrown to prevent overlapping or conflicting query states.
     *
     * Example:
     * ```php
     * $builder->table('users')->update()->set('name', "=", 'Ali')->where('id', '=', 1)->finish();
     * ```
     *
     * @throws Exception If a query is already in progress and has not been completed.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function update(){
        if (!$this->restartable()) {
            throw new Exception("You cannot start to build new query with same instance if you don't finish current one");
        }

        $this->query = "UPDATE";

        return $this;
    }

    /**
     * Adds a column-value pair to the SET clause of an UPDATE query.
     *
     * This method can only be called after starting an UPDATE query.
     * If the given value is a SQL function or constant (e.g., NOW(), UUID(), etc.),
     * it will be written directly to the query string.
     * Otherwise, a parameter placeholder (?) will be used and the value
     * will be pushed into the parameter array.
     *
     * On the first call, it adds a `SET` clause. On subsequent calls, it appends with a comma.
     *
     * Example:
     * ```php
     * $builder->update()->set('name', 'John');
     * ```
     *
     * @param string $column The column to update.
     * @param mixed $value The new value to assign. Can be a literal, SQL function, or constant.
     *
     * @throws Exception If called outside of an UPDATE query context.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function set($column, $value){
        if(strpos($this->query, "UPDATE") !== 0){
            throw new Exception("Error: Set operator only can be used on Update Queries.");
        }

        if(!strpos($this->query, "SET")) {
            if($this->checkIfItsFunctionOrConstant($value)) {
                $this->query = $this->query." SET $column = $value"; 
            } else {
                $this->query = $this->query." SET $column = ?";
                $this->params[] = $value;
            }
        } else {
            if($this->checkIfItsFunctionOrConstant($value)) {
                $this->query = $this->query.", $column = $value";
            } else {
                $this->query = $this->query.", $column = ?";
                $this->params[] = $value;
            }
        }

        return $this;
    }

    /**
     * Adds a raw SQL expression to the SET clause of an UPDATE query.
     *
     * This method is intended for use with SQL functions, constants, or subqueries
     * that should be injected directly into the query string without parameterization.
     *
     * On the first call, it adds a `SET` clause. On subsequent calls, it appends
     * additional expressions using a comma.
     *
     * Example:
     * ```php
     * $builder->update()->setExpr('updated_at', 'NOW()')->setExpr('count', 'count + 1');
     * ```
     *
     * @param string $column The column to update.
     * @param string $value A raw SQL expression (not parameterized).
     *
     * @throws Exception If called outside of an UPDATE query context.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function setExpr(string $column, string $value){
        if(strpos($this->query, "UPDATE") !== 0){
            throw new Exception("Error: Set operator only can be used on Update Queries.");
        }

        if(!strpos($this->query, "SET")) {
            $this->query = $this->query." SET $column = " . $value;
        } else {
            $this->query = $this->query.", $column = " . $value;
        }

        return $this;
    }

    /**
     * Determines whether the given value is a recognized SQL function or constant.
     *
     * Used to bypass parameter binding for values that should be written directly
     * into the SQL query (such as `NOW()` or `CURRENT_TIMESTAMP`). The check is
     * performed case-insensitively and compares against a known whitelist of
     * SQL functions and constants.
     *
     * Note: This method does not perform full SQL parsing and may miss or misidentify
     * some valid or invalid expressions. Extend the list as needed for custom functions.
     *
     * @param mixed $value The value to evaluate as a possible SQL function or constant.
     * @return bool True if the value is recognized as a SQL function or constant; false otherwise.
     */
    public function checkIfItsFunctionOrConstant($value) {
        $upper = mb_strtoupper($value);
            
        switch($upper) {
            case "NOW()":
            case "CURRENT_DATETIME":
            case "CURRENT_TIMESTAMP()":
            case "CURRENT_TIMESTAMP":
            case "UNIX_TIMESTAMP()":
            case "UNIX_TIMESTAMP":
            case "unix_timestamp()":
            case "CURDATE()":
            case "CURTIME()":
            case "CURRENT_DATE":
            case "CURRENT_TIME":
            case "CURRENT_DATE()":
            case "CURRENT_TIME()":
            case "CURRENT_DATETIME()":
            case "LOCALTIME":
            case "LOCALTIME()":
            case "LOCALTIMESTAMP":
            case "LOCALTIMESTAMP()":
            case "SYSDATE()":
            case "SYSDATE":
            case "FROM_UNIXTIME(NOW())":
            case "FROM_UNIXTIME":
            case "UTC_DATE":
            case "UTC_DATE()":
            case "UTC_TIME":
            case "UTC_TIME()":
            case "UTC_TIMESTAMP":
            case "UTC_TIMESTAMP()":
            case "GETDATE()":
                return true;
            default:
                return false;
        }
    }

    /**
     * Starts building a DELETE query.
     *
     * Initializes the internal SQL query string with the "DELETE FROM" statement.
     * Before starting, it checks if there is an unfinished query on the same instance
     * by calling {@see restartable()}. Throws an exception if a query is already in progress
     * to avoid conflicting states.
     *
     * Usage example:
     * ```php
     * $builder->delete()->table('users')->where('id', '=', 5);
     * ```
     *
     * @throws Exception If a query is already being built and not finished yet.
     *
     * @return static Returns the current instance to allow method chaining.
     */
    public function delete(){
        if (!$this->restartable()) {
            throw new Exception("You cannot start to build new query with same instance if you don't finish current one");
        }

        $this->query = "DELETE FROM";

        return $this;
    }

    /**
     * Sets the table name for the current query.
     *
     * This method appends or injects the table name into the ongoing SQL query.
     * It must be called **after** starting a query with SELECT, INSERT, DELETE, or UPDATE.
     * Otherwise, it throws an exception.
     *
     * Special handling is done for INSERT queries, replacing the table placeholder
     * after the "INSERT INTO" keyword.
     *
     * Example:
     * ```php
     * $builder->select('*')->table('users')->where('id', '=', 1);
     * $builder->insert(['name' => 'John'])->table('users');
     * ```
     *
     * @param string $table The name of the table to operate on.
     *
     * @throws Exception If called before starting a query type (SELECT, INSERT, DELETE, UPDATE).
     *
     * @return static Returns the current instance for method chaining.
     */
    public function table(string $table) {
        if(strpos($this->query, "SELECT") !== 0 && 
           strpos($this->query, "INSERT") !== 0 && 
           strpos($this->query, "DELETE") !== 0 && 
           strpos($this->query, "UPDATE") !== 0) {
             throw new Exception("You cannot call ->table() function before actually build your query.");
        } else {
            if(strpos($this->query, "INSERT INTO") === 0){
                $query = explode(" INTO ", $this->query);

                $this->query = implode(" ", [$query[0], "INTO $table", $query[1]]);
            } else {
                $this->query = $this->query." ".$table;
            }
        }

        return $this;
    }

    /**
     * Starts building a COUNT query for the specified table.
     *
     * Initializes the SQL query string with a SELECT COUNT(*) statement, aliasing the result as `count`.
     * This method resets any previous query state.
     *
     * Example:
     * ```php
     * $builder->count('users')->where('status', '=', 'active');
     * ```
     *
     * @param string $table The name of the table to count rows from.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function count($table) {
        $this->query = "SELECT COUNT(*) AS count FROM $table";

        return $this;
    }

    /**
     * Finalizes the current SQL query by appending a semicolon.
     *
     * This method ensures that a valid query has been started (SELECT, INSERT, DELETE, or UPDATE)
     * before appending a semicolon to mark the end of the query string.
     * Calling this method without a started query will throw an exception.
     *
     * Example:
     * ```php
     * $builder->select('*')->table('users')->where('id', '=', 1)->finish();
     * ```
     *
     * @throws Exception If no valid query has been started before calling this method.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function finish(){
        if(strpos($this->query, "SELECT") !== 0 && 
           strpos($this->query, "INSERT") !== 0 && 
           strpos($this->query, "DELETE") !== 0 && 
           strpos($this->query, "UPDATE") !== 0) {
                throw new Exception("You cannot call ->finish() function before actually build your query.");
        } else {
            $this->query = $this->query.";";
        }

        return $this;
    }

    /**
     * Executes the currently built SQL query using the PDO connection.
     *
     * Prepares the SQL statement, binds any accumulated parameters, and executes the query.
     * Throws an exception if no valid query has been started (i.e., no SELECT, INSERT, DELETE, or UPDATE found).
     *
     * @throws Exception If called before building a valid query.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function execute(){
        if(strpos($this->query, "SELECT") === false && 
           strpos($this->query, "INSERT") === false && 
           strpos($this->query, "DELETE") === false && 
           strpos($this->query, "UPDATE") === false) {
                throw new Exception("You cannot call .execute() function before actually build your query.");
        } else {
            $stmt = $this->connection->prepare($this->query);

            $stmt->execute($this->params);
            $this->recentAction = $stmt;

            $this->params = [];
        
            return $this;
        }

        return $this;
    }

    /**
     * Appends a custom raw SQL snippet to the current query with optional parameters.
     *
     * This allows adding arbitrary SQL parts to the query builder,
     * and merging additional parameters to be bound during execution.
     *
     * Example:
     * ```php
     * $builder->select('*')->table('users')->appendCustom('WHERE age > ?', [18])->execute();
     * ```
     *
     * @param string $customQuery The raw SQL string to append.
     * @param array $params Optional parameters to bind for the appended query part.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function appendCustom(string $customQuery, array $params = []) {
        $this->query = $this->query." ".$customQuery;

        if(count($params) > 0) {
            $this->params = array_merge($this->params, $params);
        }

        return $this;
    }

    /**
     * Completely replaces the current query with a custom raw SQL query and optional parameters.
     *
     * This method discards any previously built query and sets the provided raw SQL string as the new query.
     * Additional parameters can be provided to bind during execution.
     *
     * Example:
     * ```php
     * $builder->completeCustom('SELECT * FROM users WHERE status = ?', ['active'])->execute();
     * ```
     *
     * @param string $customQuery The raw SQL query string to set.
     * @param array $params Optional parameters to bind with the query.
     *
     * @return static Returns the current instance for method chaining.
     */
    public function completeCustom(string $customQuery, array $params = []) {
        $this->query = $customQuery;

        if(count($params) > 0) {
            $this->params = array_merge($this->params, $params);
        }

        return $this;
    }

    /**
     * Retrieves the result of the last executed query.
     *
     * - For INSERT queries, returns the last inserted ID.
     * - For SELECT queries, returns all fetched rows as an associative array.
     * - For DELETE and UPDATE queries, returns the number of affected rows.
     *
     * Throws an exception if called for an unsupported or invalid query type.
     *
     * @throws Exception If the query type is invalid or unsupported.
     *
     * @return mixed The query result depending on the query type:
     *               - string for last insert ID,
     *               - array for SELECT results,
     *               - int for affected rows count.
     */
    public function result() {
        if(strpos($this->query, "INSERT") === 0){
            return $this->connection->lastInsertId();
        }

        if(strpos($this->query, "SELECT") === 0){
            return $this->recentAction->fetchAll(PDO::FETCH_ASSOC);
        }

        if(strpos($this->query, "DELETE") === 0 || strpos($this->query, "UPDATE") === 0){
            return $this->recentAction->rowCount();
        }

        throw new Exception("Invalid query");
    }

    public function close(){
        return $this;
    }
}
    ?>