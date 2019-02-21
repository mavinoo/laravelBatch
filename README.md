# Laravel BATCH (BULK)
Insert and update batch (bulk) in laravel

# Install
`composer require mavinoo/laravel-batch:dev-master`

NOTE: [Click to install the previous version 1.0](https://github.com/mavinoo/laravelBatch/tree/v1.0).

# Service Provider
file app.php in array providers :

`Mavinoo\LaravelBatch\LaravelBatchServiceProvider::class,`

# Aliases
file app.php in array aliases :

`'Batch' => Mavinoo\LaravelBatch\LaravelBatchFacade::class,`


# Example Update 1

```
use App\Models\User;

$userInstance = new User;
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

Batch::update($userInstance, $value, $index);
```

# Example Update 2

```
use App\Models\User;

$userInstance = new User;
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

Batch::update($userInstance, $value, $index);
```

# Example Insert

```
use App\Models\User;

$userInstance = new User;
$columns = [
     'firstName',
     'lastName',
     'email',
     'isActive',
     'status',
];
$values = [
     [
         'Mohammad',
         'Ghanbari',
         'emailSample_1@gmail.com',
         '1',
         '0',
     ] ,
     [
         'Saeed',
         'Mohammadi',
         'emailSample_2@gmail.com',
         '1',
         '0',
     ] ,
     [
         'Avin',
         'Ghanbari',
         'emailSample_3@gmail.com',
         '1',
         '0',
     ] ,
];
$batchSize = 500; // insert 500 (default), 100 minimum rows in one query

$result = Batch::insert($userInstance, $columns, $values, $batchSize);
```

```
// result : false or array

sample array result:
Array
(
    [totalRows]  => 384
    [totalBatch] => 500
    [totalQuery] => 1
)
```
