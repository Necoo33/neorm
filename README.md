# Necdetiye Object Relational Mapper For MySql

This is a very powerfull orm for mysql that gives you the full control of your queries.

It currently supports `SELECT`, `INSERT` and `DELETE` queries.

It supports this operators: `WHERE`, `OR`, `OFFSET`, `LIKE`, `ORDER BY`.

it also includes sanitization, make multiple queries with same instance etc.

It usually follows the sql query synthax, for example:

For building: `SELECT * FROM users WHERE name = 'necdet';` query, you have to write this:

```php

$orm = new Neorm($host, $username, $password, $db);

// build the query;

$orm = $orm->select("*")->table("users")->where("name", "=", "necdet")->finish();

// then run that query;

$user = $orm->execute()->result(); // if you run the "->result()" method when you do a select query, it returns the rows. If you do insert query, don't run this function.

// since you can do multiple queries with it, restart it however you like:

$orm = $orm->insert(["nickname" => "necoo33", "email" => "arda_etiman_799@windowslive.com"])->table("users")->finish()->execute(); // end insert queries with "execute" function.

```

Also you can run search queries like that:

```php

$orm = new Neorm($host, $username, $password, $db);

// that code builds that query: 
// "SELECT id, title, price, description FROM products WHERE title = 'your search text' OR description = 'your search text' ORDER BY title ASC LIMIT 5 OFFSET 0;"

$productQuery = $orm->select("id", "title", "price", "description")
                    ->table("products")
                    ->like(["title", "description"], "your search text")
                    ->orderBy("title", "ASC")
                    ->limit(5)
                    ->offset(0)
                    ->finish();

// take the result:

$products = $productQuery->execute()->result();

// then close the database connection if you don't build another query:

$productQuery->close();

```
