# UPDATE BATCH (BULK) IN LARAVEL
update batch (bulk) laravel

# Install
`composer require mavinoo/update-batch:dev-master`


# Service Provider
file app.php in array providers :

`Mavinoo\UpdateBatch\UpdateBatchServiceProvider::class,`


# Aliases
file app.php in array aliases :

`'UpdateBatch' => Mavinoo\UpdateBatch\UpdateBatchFacade::class,`


# Example 1

```
$table = 'users';

$value = [
     [
         'id' => 1,
         'status' => 'active',
         'nickname' => 'Mohammad'
     ] ,
     [
         'id' => 5,
         'status' => 'deactive',
         'nickname' => 'Ghanbari'
     ] ,
];

$index = 'id';


UpdateBatch::updateBatch($table, $value, $index);
```


# Example 2

```
$table = 'users';

$value = [
     [
         'id' => 1,
         'status' => 'active'
     ],
     [
         'id' => 5,
         'status' => 'deactive',
         'nickname' => 'Ghanbari'
     ],
     [
         'id' => 10,
         'status' => 'active',
         'date' => Carbon::now()
     ],
     [
         'id' => 11,
         'username' => 'mavinoo'
     ]
];

$index = 'id';

UpdateBatch::updateBatch($table, $value, $index);
```
