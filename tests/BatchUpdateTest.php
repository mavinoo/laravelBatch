<?php
require_once ('BootstrapDatabase.php');
use Carbon\Carbon;

class BatchUpdateTest extends BootstrapDatabase
{
    public $columns = [
        'email',
        'password',
        'name',
        'status',
    ];

    private function insert()
    {
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

        $result = Batch::insert($this->model, $this->columns, $values, $batchSize);

        $this->assertIsArray($result);
        $this->assertTrue($result['totalRows'] == 3);
        $this->assertTrue($result['totalBatch'] == 500);
    }

    public function testBatchUpdateWithFacade()
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

    public function testBatchUpdateWithHelper()
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

        $result = batch()->update($this->model, $columnValues, $index);

        $this->assertTrue($result == 3);
        $this->model->truncate();
    }
}
