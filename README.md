# Laravel BATCH (BULK)

Insert and update batch (bulk) in laravel

[![License](https://poser.pugx.org/mavinoo/laravel-batch/license)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Latest Stable Version](https://poser.pugx.org/mavinoo/laravel-batch/v/stable)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Total Downloads](https://poser.pugx.org/mavinoo/laravel-batch/downloads)](https://packagist.org/packages/mavinoo/laravel-batch)
[![Daily Downloads](https://poser.pugx.org/mavinoo/laravel-batch/d/daily)](https://packagist.org/packages/mavinoo/laravel-batch)

# Install

`composer require mavinoo/laravel-batch`

# Service Provider

file app.php in array providers :

`Mavinoo\Batch\BatchServiceProvider::class,`

# Aliases

file app.php in array aliases :

`'Batch' => Mavinoo\Batch\BatchFacade::class,`

# Example Update Multiple Condition

```php
use App\Models\User;

$userInstance = new User;
$arrays = [
     [
         'conditions' => ['id' => 1, 'status' => 'active'],
         'columns'    => [
             'status' => 'invalid'
             'nickname' => 'mohammad'
         ],
     ],
     [
         'conditions' => ['id' => 2],
         'columns'    => [
             'nickname' => 'mavinoo',
             'name' => 'mohammad',
         ],
     ],
     [
         'conditions' => ['id' => 3],
         'columns'    => [
             'nickname' => 'ali'
         ],
     ],
];
$keyName = 'id';

Batch::updateMultipleCondition($userInstance, $arrays, $keyName);
or
batch()->updateMultipleCondition($userInstance, $arrays, $keyName);
```

# Example Update 2

```php
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
or
batch()->update($userInstance, $values, $index);
```

# Example Update 3

```php
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
or
batch()->update($userInstance, $values, $index);
```

# Example Increment / Decrement

```php
use App\Models\User;

$userInstance = new User;
$value = [
     [
         'id' => 1,
         'balance' => ['+', 500] // Add
     ] ,
     [
         'id' => 2,
         'balance' => ['-', 200] // Subtract
     ] ,
     [
         'id' => 3,
         'balance' => ['*', 5] // Multiply
     ] ,
     [
         'id' => 4,
         'balance' => ['/', 2] // Divide
     ] ,
     [
         'id' => 5,
         'balance' => ['%', 2] // Modulo
     ] ,
];
$index = 'id';

Batch::update($userInstance, $value, $index);
or
batch()->update($userInstance, $values, $index);
```

# Example Insert

```php
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
or
$result = batch()->insert($userInstance, $values, $index);
```

```php
// result : false or array

sample array result:
Array
(
    [totalRows]  => 384
    [totalBatch] => 500
    [totalQuery] => 1
)
```

# Example called from model

Add `HasBatch` trait into model:

```php
namespace App\Models;

use Mavinoo\Batch\Traits\HasBatch;

class User extends Model
{
    use HasBatch;
}
```

And call `batchUpdate()` or `batchInsert()` from model:

```php
use App\Models\User;

// ex: update
User::batchUpdate($value, $index);

// ex: insert
User::batchInsert($columns, $values, $batchSize);
```

# Helper batch()

```php
// ex: update

$result = batch()->update($userInstance, $value, $index);


// ex: insert

$result = batch()->insert($userInstance, $columns, $values, $batchSize);
```

# Tests

If you don't have phpunit installed on your project, first run `composer require phpunit/phpunit`

In the root of your laravel app, run `./vendor/bin/phpunit ./vendor/mavinoo/laravel-batch/tests`

# Donate

USDT Address: 0x98410956169cdd00a43fe895303bdca096f37062
