<?php
require_once ('TestModel.php');

use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class BootstrapDatabase extends TestCase
{
    public $model;

    public function setUp(): void
    {
        parent::setUp();
        $tableName = (new TestModel())->tableName();
        if (!Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->increments('id');
                $table->string('name')->unique();
                $table->string('email')->unique();
                $table->string('password');
                $table->string('status')->nullable();
                $table->rememberToken();
                $table->timestamps();
            });
        }

        $this->model = new TestModel();
        $this->model->truncate();

    }


    public function tearDown(): void
    {
        Schema::dropIfExists((new TestModel())->tableName());
    }
}
