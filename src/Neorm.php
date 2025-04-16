<?php
class Neorm {
    public $query = "";
    public $table = "";
    public $connection;
    public $recentAction;
    public $dangerousItems = [];

    public function __construct($host, $name, $pass, $db, $port = 3306)
    {
        $this->connection = mysqli_connect($host, $name, $pass, $db, $port);

        $this->load_dangerous_strings();

        if(!$this->connection) {
            throw new Exception("cannot connect to database");
        }
    }

    function load_dangerous_strings() {
        $this->dangerousItems =  [";", "; drop", "admin' #", "admin'/*", "; union", "or 1 = 1",
        "or 1 = 1#", "or 1 = 1/*", "or true = true", "or false = false", "or '1' = '1'", "or '1' = '1'#",
        "or '1' = '1'/*", "; sleep(", "--", "drop table", "drop schema", "select if", "union select",
        "union all", "exec", "master..", "masters..", "information_schema", "load_file", "alter user"];
    }

    function sanitization($item) {
        switch(gettype($item)){
            case "array":
                for($i = 0; $i < count($this->dangerousItems); $i++) {
                    for($p = 0; $p < count($item); $p++) {
                        if(strpos($item[$p], $this->dangerousItems[$i])) {
                            return false; 
                        }
                    }
                }

                break;
            default:
                for($i = 0; $i < count($this->dangerousItems); $i++) {
                    if(strpos($item, $this->dangerousItems[$i])) {
                        return false; 
                    }
                }

                break;
        }

        return true;
    }

    // seçilecek sütunları bir normal array olarak ekle.
    public function select(string|array $fields){
        if(!$this->restartable()) {
            throw new Exception("You cannot start to build new query with same instance if you don't finish current one");
        } 

        switch(gettype($fields)) {
            case "string":
                if(!$this->sanitization($fields)) throw new Exception("Dangerous user input detected.");
                break;
            case "array":
                for($i = 0; $i < count($fields); $i++) {
                    if(!$this->sanitization($fields[$i])) throw new Exception("Dangerous user input detected.");
                }

                break;
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
                    $sanitizedField = $this->connection->real_escape_string($fields[$i]);

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
        if(!$this->sanitization($limit)) throw new Exception("Dangerous user input detected.");

        $limit = intval($this->connection->real_escape_string($limit));
        $this->query = $this->query." LIMIT ".$limit;

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

    public function offset(int $offset) {
        if(!$this->sanitization($offset)) throw new Exception("Dangerous user input detected.");

        $offset = intval($this->connection->real_escape_string($offset));
        $this->query = $this->query." OFFSET ".$offset;

        return $this;
    }

    public function where(string $column, string $mark, $value) {
        if(!$this->sanitization($column)) throw new Exception("Dangerous user input detected.");
        if(!$this->sanitization($mark)) throw new Exception("Dangerous user input detected.");
        if(!$this->sanitization($value)) throw new Exception("Dangerous user input detected.");

        $column = $this->connection->real_escape_string($column);
        $mark = $this->connection->real_escape_string($mark);

        switch(gettype($value)) {
            case "string": 
                $value = $this->connection->real_escape_string($value);
                break;
            case "integer":
                $value = intval($this->connection->real_escape_string($value));
                break;
            case "boolean":
                $evaluation = $this->connection->real_escape_string($value);
                if($evaluation === "true") {
                    $value = true;
                } else {
                    $value = false;
                }
            case "double":
                $value = floatval($this->connection->real_escape_string($value));
                break;
            default:
                throw new Exception("Invalid input for value input");
                break;
        }

        if(gettype($value) === "string"){
            $this->query = $this->query." WHERE $column $mark '$value'";
        } else {
            $this->query = $this->query." WHERE $column $mark $value";
        }

        return $this;
    }

    public function or(string $column, string $mark, $value){
        if(!$this->sanitization($column)) throw new Exception("Dangerous user input detected.");
        if(!$this->sanitization($mark)) throw new Exception("Dangerous user input detected.");
        if(!$this->sanitization($value)) throw new Exception("Dangerous user input detected.");

        $column = $this->connection->real_escape_string($column);
        $mark = $this->connection->real_escape_string($mark);
        
        switch(gettype($value)) {
            case "string": 
                $value = $this->connection->real_escape_string($value);
                break;
            case "integer":
                $value = intval($this->connection->real_escape_string($value));
                break;
            case "boolean":
                $evaluation = $this->connection->real_escape_string($value);
                if($evaluation === "true") {
                    $value = true;
                } else {
                    $value = false;
                }
            case "double":
                $value = floatval($this->connection->real_escape_string($value));
                break;
            case "NULL":
                break;
            default:
                throw new Exception("Invalid input for value input");
                break;
        }

        if(gettype($value) === "string"){
            $this->query = $this->query." OR $column $mark '$value'";
        } else {
            $this->query = $this->query." OR $column $mark $value";
        }

        return $this;
    }

    public function and(string $column, string $mark, $value){
        if(!$this->sanitization($column)) throw new Exception("Dangerous user input detected.");
        if(!$this->sanitization($mark)) throw new Exception("Dangerous user input detected.");
        if(!$this->sanitization($value)) throw new Exception("Dangerous user input detected.");

        $column = $this->connection->real_escape_string($column);
        $mark = $this->connection->real_escape_string($mark);

        switch(gettype($value)) {
            case "string": 
                $value = $this->connection->real_escape_string($value);
                break;
            case "integer":
                $value = intval($this->connection->real_escape_string($value));
                break;
            case "boolean":
                $evaluation = $this->connection->real_escape_string($value);
                if($evaluation === "true") {
                    $value = true;
                } else {
                    $value = false;
                }
            case "double":
                $value = floatval($this->connection->real_escape_string($value));
                break;
            case "NULL":
                break;
            default:
                throw new Exception("Invalid input for value input");
                break;
        }

        if(gettype($value) === "string"){
            $this->query = $this->query." AND $column $mark '$value'";
        } else if(gettype($value) === "string") {
            switch($mark) {
                case "=":
                    $this->query = $this->query." AND $column IS NULL";
                    break;
                case "!=":
                case "<>":
                    $this->query = $this->query." AND $column IS NOT NULL";
                    break;
                default:
                    $this->query = $this->query." AND $column IS NOT NULL";
                    break;
            }
        } else {
            $this->query = $this->query." AND $column $mark $value";
        }

        return $this;
    }

    public function like(array $columns, string $operand) {
        for($i = 0; $i < count($columns); $i++) {
            if(!$this->sanitization($columns[$i])) throw new Exception("Dangerous user input detected.");
        }

        if(!$this->sanitization($operand)) throw new Exception("Dangerous user input detected.");

        if(strpos($this->query, "SELECT") !== 0 &&
           strpos($this->query, "DELETE") !== 0 &&
           strpos($this->query, "UPDATE") !== 0){
            throw new Exception("LIKE kıstasları 'SELECT', 'DELETE' veya 'UPDATE' query'lerine tatbik edilmelidir.");
        }
        $operand = $this->connection->real_escape_string($operand);

        for($i = 0; $i < count($columns); $i ++) {
            if(gettype($columns[$i]) !== "string"){
                throw new Exception("Sütunların hepsi string tipinden olmalıdır.");
            }
            
            if($i === 0) {
                $this->query = $this->query." WHERE $columns[$i] LIKE '%$operand%'";
            } else {
                $this->query = $this->query." OR $columns[$i] LIKE '%$operand%'";
            }
        }

        return $this;
    }

    // bu fonksiyonlardan birden fazla kullanacaksan ard arda
    // kullanmayı unutma:
    public function orderBy(?string $column = null, ?string $ordering = null){
        if(!$this->sanitization($column)) throw new Exception("Dangerous user input detected.");
        if(!$this->sanitization($ordering)) throw new Exception("Dangerous user input detected.");

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
            case "asc": $ordering = strtoupper($ordering);
            case "desc":
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
        for($i = 0; $i < count($fields); $i++) {
            if(!$this->sanitization($fields[$i])) throw new Exception("Dangerous user input detected.");
        }

        if(!$this->sanitization($column)) throw new Exception("Dangerous user input detected.");

        if(strpos($this->query, "ORDER BY")) {
            $fieldsString = "";

            for($i = 0; $i < count($fields); $i++) {
                if($i === 0) {
                    $field = $fields[$i];
                    $fieldsString = $fieldsString."$field";
                } else {
                    $field = $fields[$i];
                    $fieldsString = $fieldsString.", $field";
                }
            }

            $this->query = $this->query.", FIELD($column, $fieldsString)";
        } else {
            $fieldsString = "";

            for($i = 0; $i < count($fields); $i++) {
                if($i === 0) {
                    $field = $fields[$i];
                    $fieldsString = $fieldsString."$field";
                } else {
                    $field = $fields[$i];
                    $fieldsString = $fieldsString.", $field";
                }
            }
            
            $this->query = $this->query." ORDER BY FIELD($column, $fieldsString)";
        }

        return $this;
    }

    /* bu kodun doğru çalışması için  */
    public function insert(array $insertObject) {
        $keys = array_keys($insertObject);
        $values = array_values($insertObject);

        for($i = 0; $i < count($keys); $i++) {
            if(!$this->sanitization($keys[$i])) throw new Exception("Dangerous user input detected.");
        }

        for($i = 0; $i < count($values); $i++) {
            if(!$this->sanitization($values[$i])) throw new Exception("Dangerous user input detected.");
        }

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
            switch(gettype($values[$p])) {
                case "string": 
                    $value = $this->connection->real_escape_string($values[$p]);

                    if($p + 1 === $valuesLength) {
                        $this->query = $this->query."'$value');";
                    } else {
                        $this->query = $this->query."'$value', ";
                    }

                    break;
                case "integer":
                    $value = intval($this->connection->real_escape_string($values[$p]));

                    if($p + 1 === $valuesLength) {
                        $this->query = $this->query."$value)";
                    } else {
                        $this->query = $this->query."$value, ";
                    }
                    break;
                case "boolean":
                    $evaluation = $this->connection->real_escape_string($values[$p]);

                    if($evaluation === "true") {
                        $value = true;
                    } else {
                        $value = false;
                    }

                    if($p + 1 === $valuesLength) {
                        $this->query = $this->query."$value)";
                    } else {
                        $this->query = $this->query."$value, ";
                    }
                case "double":
                    $value = floatval($this->connection->real_escape_string($values[$p]));

                    if($p + 1 === $valuesLength) {
                        $this->query = $this->query."$value)";
                    } else {
                        $this->query = $this->query."$value, ";
                    }

                    break;
                default:
                    throw new Exception("Invalid input for value input");
                    break;
            }
        }

        return $this;
    }

    public function update(){
        $this->query = "UPDATE";

        return $this;
    }

    public function set($column, $value){
        if(!$this->sanitization($column)) throw new Exception("Dangerous user input detected.");
        if(!$this->sanitization($value)) throw new Exception("Dangerous user input detected.");

        if(strpos($this->query, "UPDATE") !== 0){
            throw new Exception("Error: Set operator only can be used on Update Queries.");
        }

        $column = $this->connection->real_escape_string($column);

        switch(gettype($value)){
            case "string":
            case "String":
                $value = $this->connection->real_escape_string($value);

                if(!strpos($this->query, "SET")) {
                    $this->query = $this->query." SET $column = '$value'";   
                } else {
                    $this->query = $this->query.", $column = '$value'";
                }

                break;
            case "integer":
            case "Integer":
                $value = intval($this->connection->real_escape_string($value));
                
                if(!strpos($this->query, "SET")) {
                    $this->query = $this->query." SET $column = $value";   
                } else {
                    $this->query = $this->query.", $column = $value";
                }

                break;
            case "float":
            case "Float":
                $value = floatval($this->connection->real_escape_string($value));
                
                if(!strpos($this->query, "SET")) {
                    $this->query = $this->query." SET $column = $value";   
                } else {
                    $this->query = $this->query.", $column = $value";
                }

                break;
            case "boolean":
            case "Boolean":
                $value = $this->connection->real_escape_string($value);

                if($value === "true"){
                    $value = true;
                } else {
                    $value = false;
                }

                if(!strpos($this->query, "SET")) {
                    $this->query = $this->query." SET $column = $value";   
                } else {
                    $this->query = $this->query.", $column = $value";
                }

                break;
            case "NULL":
            case "null":
                if(!strpos($this->query, "SET")) {
                    $this->query = $this->query." SET $column = NULL";   
                } else {
                    $this->query = $this->query.", $column = NULL";
                }

                break;
            default:
                if(!strpos($this->query, "SET")) {
                    $this->query = $this->query." SET $column = $value";   
                } else {
                    $this->query = $this->query.", $column = $value";
                }
        }

        return $this;
    }

    public function delete(){
        $this->query = "DELETE FROM";

        return $this;
    }

    public function table(string $table) {
        if(!$this->sanitization($table)) throw new Exception("Dangerous user input detected.");

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
        if(!$this->sanitization($table)) throw new Exception("Dangerous user input detected.");

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
            $this->recentAction = mysqli_query($this->connection, $this->query);
        }

        return $this;
    }

    public function result() {
        if(strpos($this->query, "INSERT") === 0){
            return mysqli_insert_id($this->connection);
        } else {
            return mysqli_fetch_all($this->recentAction, MYSQLI_ASSOC);
        }
    }

    public function close(){
        mysqli_close($this->connection);

        return $this;
    }
}

    ?>