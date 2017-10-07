# UPDATE BATCH IN LARAVEL
update batch laravel

# Install
`composer require mavinoo/update-batch:dev-master`


# Service Provider
file app.php in array providers :

`Mavinoo\UpdateBatch\UpdateBatchServiceProvider::class,`


# Aliases
file app.php in array aliases :

`'UpdateBatch' => Mavinoo\UpdateBatch\UpdateBatchFacade::class,`


# Example

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
