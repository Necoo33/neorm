<?php

namespace Necdet\Neorm;

class Neorm {
    public $query = "";
    public $params = [];
    public $table = "";
    public $connection;
    public $recentAction;

    public function __construct($host, $name, $pass, $db, $port = 3306)
    {
        //$this->connection = mysqli_connect($host, $name, $pass, $db, $port);

        $this->connection = new PDO("mysql:host=$host;dbname=$db;port=$port", $name, $pass);
        $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        if(!$this->connection) {
            throw new Exception("cannot connect to database");
        }
    }

    // seçilecek sütunları bir normal array olarak ekle.
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

    public function limit(string|int $limit) {
        if(is_numeric($limit)) {
            $limit = intval($limit);
        } else {
            throw new Exception("Limit parameter has to be an integer");
        }

        $this->query = $this->query." LIMIT ?";

        $this->params[] = $limit;

        return $this;
    }

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

    public function offset(string|int $offset) {
        if(is_numeric($offset)) {
            $offset = intval($offset);
        } else {
            throw new Exception("Offset parameter has to be an integer");
        }

        $this->query = $this->query." OFFSET ?";

        $this->params[] = $offset;

        return $this;
    }

    public function where(string $column, string $mark, $value) {
        switch(gettype($value)) {
            case "string": 
            case "integer":
            case "double":
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
            $this->query = $this->query . $newChunk . "$column $mark ?";
            $this->params[] = $value;
        }

        return $this;
    }

    public function join(string $type, string $table, ?string $left = null, ?string $mark = null, ?string $right = null) {
        $joinType = "";

        switch($type) {
            case "inner":
            case "INNER":
            case "Inner":
                $joinType = "INNER";
                break;
            case "left":
            case "LEFT":
            case "Left":
                $joinType = "LEFT";
                break;
            case "right":
            case "RIGHT":
            case "Right":
                $joinType = "RIGHT";
                break;
            case "cross":
            case "CROSS":
            case "Cross":
                $joinType = "CROSS";
                break;
            case "natural":
            case "NATURAL":
            case "Natural":
                $joinType = "NATURAL";
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

        switch($mode) {
            case "where":    
            case "WHERE":
            case "Where":
                $this->query = $this->query." WHERE $column IN ($valuesString)";
                break;
            case "and":
            case "AND":
            case "And":
                $this->query = $this->query." AND $column IN ($valuesString)";
                break;
            case "or":
            case "OR":
            case "Or":
                $this->query = $this->query." OR $column IN ($valuesString)";
                break;
        }

        return $this;
    }

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

        switch($mode) {
            case "where":
                $this->query = $this->query." WHERE $column NOT IN ($valuesString)";
                break;
            case "and":
                $this->query = $this->query." AND $column NOT IN ($valuesString)";
                break;
            case "or":
                $this->query = $this->query." OR $column NOT IN ($valuesString)";
                break;
        }

        return $this;
    }

    public function or(string $column, string $mark, $value) {
        switch(gettype($value)) {
            case "string": 
            case "integer":
            case "double":
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
            $this->query = $this->query . $newChunk . "$column $mark ?";
            $this->params[] = $value;
        }

        return $this;
    }

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
            $this->query = $this->query . $newChunk . "$column $mark ?";
            $this->params[] = $value;
        }

        return $this;
    }

    public function open_parenthesis(string $type){
        $upper = trim(strtoupper($type));

        switch($upper) {
            case "WHERE":
            case "AND":
            case "OR":
                break;
            default:
                throw new Exception("Parantez tipi olarak, şimdilik sadece 'WHERE', 'AND' ve 'OR' anahtar kelimeleri desteklenmektedir.");
                break;
        }

        $this->query = $this->query . " $upper (";

        return $this;
    }

    public function close_parenthesis(){
        if(strrpos($this->query, "(") === false) {
            throw new Exception("Hiçbir parantez açılmadıysa, parantez kapatamazsınız.");
        }

        $this->query = $this->query . ")";

        return $this;
    }

    public function like(array $columns, string $operand) {
        if(strpos($this->query, "SELECT") !== 0 &&
           strpos($this->query, "DELETE") !== 0 &&
           strpos($this->query, "UPDATE") !== 0){
            throw new Exception("LIKE kıstasları 'SELECT', 'DELETE' veya 'UPDATE' query'lerine tatbik edilmelidir.");
        }

        for($i = 0; $i < count($columns); $i ++) {
            if(gettype($columns[$i]) !== "string"){
                throw new Exception("Sütunların hepsi string tipinden olmalıdır.");
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

    // bu fonksiyonlardan birden fazla kullanacaksan ard arda
    // kullanmayı unutma:
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

        switch($ordering) {
            case "asc": 
            case "desc":
                $ordering = strtoupper($ordering);
                break;
            case "ASC": $ordering;
            case "DESC": $ordering;
                break;
            case "rand":
            case "RAND":
            case "random":
            case "RANDOM":
            case "":
                $ordering = "RAND()";
                break;

            default:
                throw new Exception("Ordering option has to be either asc or desc.");
        }

        if(strpos($this->query, "ORDER BY") !== false){
            if($ordering === "RAND()" && (strpos($this->query, "ASC") || strpos($this->query, "DESC"))){
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

    public function groupBy(string $column) {
        $this->query = $this->query." GROUP BY $column";

        return $this;
    }

    /* bu kodun doğru çalışması için  */
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
                $this->query = $this->query."?);";
                $this->params[] = $value;
            } else {
                $this->query = $this->query."?, ";
                $this->params[] = $value;
            }
        }

        return $this;
    }

    public function update(){
        $this->query = "UPDATE";

        return $this;
    }

    public function set($column, $value){
        if(strpos($this->query, "UPDATE") !== 0){
            throw new Exception("Error: Set operator only can be used on Update Queries.");
        }

        if(!strpos($this->query, "SET")) {
            $this->query = $this->query." SET $column = ?";   
            $this->params[] = $value;
        } else {
            $this->query = $this->query.", $column = ?";
            $this->params[] = $value;
        }

        return $this;
    }

    public function delete(){
        $this->query = "DELETE FROM";

        return $this;
    }

    public function table(string $table) {
        if(strpos($this->query, "SELECT") !== 0 && 
           strpos($this->query, "INSERT") !== 0 && 
           strpos($this->query, "DELETE") !== 0 && 
           strpos($this->query, "UPDATE") !== 0) {
             throw new Exception("You cannot call .table() function before actually build your query.");
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

    public function count($table) {
        $this->query = "SELECT COUNT(*) AS count FROM $table";

        return $this;
    }

    public function finish(){
        if(strpos($this->query, "SELECT") !== 0 && 
           strpos($this->query, "INSERT") !== 0 && 
           strpos($this->query, "DELETE") !== 0 && 
           strpos($this->query, "UPDATE") !== 0) {
                throw new Exception("You cannot call .finish() function before actually build your query.");
        } else {
            $this->query = $this->query.";";
        }

        return $this;
    }

    public function execute(){
        if(strpos($this->query, "SELECT") !== 0 && 
           strpos($this->query, "INSERT") !== 0 && 
           strpos($this->query, "DELETE") !== 0 && 
           strpos($this->query, "UPDATE") !== 0) {
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

    public function appendCustom(string $customQuery, array $params = []) {
        $this->query = $this->query." ".$customQuery;

        if(count($params) > 0) {
            $this->params = array_merge($this->params, $params);
        }

        return $this;
    }

    public function completeCustom(string $customQuery, array $params = []) {
        $this->query = $customQuery;

        if(count($params) > 0) {
            $this->params = array_merge($this->params, $params);
        }

        return $this;
    }

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