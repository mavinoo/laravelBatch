<?php
use Carbon\Carbon;
use Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class TestModel extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'HmKTjCJgLN9bBq7KXzI3';

    public function tableName()
    {
        return $this->table;
    }
}

class BatchInsertUpdateTest extends TestCase
{
    private $model;
    private $tableName;

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

    private function insert()
    {
        $columns = [
            'email',
            'password',
            'name',
            'status',
        ];

        $values = [
            [
                'djunehor@gmail.com',
                bcrypt('djunehor'),
                'djunehor',
                'active'
            ],
            [
                'samuel@gmail.com',
                bcrypt('samuel'),
                'samuel',
                'whodey'
            ],
            [
                'general@gmail.com',
                bcrypt('general'),
                'general',
                'inactive',
            ]
        ];

        $batchSize = 500; // insert 500 (default), 100 minimum rows in one query

        $result = Batch::insert($this->model, $columns, $values, $batchSize);

        return $result;
    }


    public function testBatchInsert()
    {
        $result = $this->insert();

        $this->assertIsArray($result);
        $this->assertTrue($result['totalRows'] == 3);
        $this->assertTrue($result['totalBatch'] == 500);
        $this->model->truncate();
    }

    public function testBatchInsertIncorrectColumnCount()
    {

        $columns = [
            'email',
            'password',
            'name',
            'status',
        ];

        $values = [
            [
                'djunehor@gmail.com',
                bcrypt('djunehor'),
                'djunehor',
            ],
            [
                'samuel@gmail.com',
                bcrypt('samuel'),
                'samuel',
                'whodey'
            ],
            [
                'general@gmail.com',
                bcrypt('general'),
                'general',
                'inactive',
            ]
        ];
        $batchSize = 500; // insert 500 (default), 100 minimum rows in one query

        $result = Batch::insert($this->model, $columns, $values, $batchSize);
        $this->assertFalse($result);
    }

    public function testBatchUpdate()
    {
        $this->insert();
        $columnValues = [
            [
                'id' => 1,
                'status' => 'amala'
            ],
            [
                'id' => 2,
                'status' => 'deactive',
                'name' => 'Ghanbari'
            ],
            [
                'id' => 3,
                'status' => 'active',
                'created_at' => Carbon::now()
            ]
        ];
        $index = 'id';

        $result = Batch::update($this->model, $columnValues, $index);

        $this->assertTrue($result == 3);
        $this->model->truncate();
    }
}
