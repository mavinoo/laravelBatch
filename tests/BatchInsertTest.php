<?php
require_once ('BootstrapDatabase.php');

class BatchInsertTest extends BootstrapDatabase
{
    public $columns = [
        'email',
        'password',
        'name',
        'status',
    ];

    public function testBatchInsertWithFacade()
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

        $result = Batch::insert($this->model, $this->columns, $values, $batchSize);
        $this->assertFalse($result);
    }


    public function testBatchInsertWithHelper()
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

        $result = batch()->insert($this->model, $this->columns, $values, $batchSize);

        $this->assertIsArray($result);
        $this->assertTrue($result['totalRows'] == 3);
        $this->assertTrue($result['totalBatch'] == 500);
        $this->model->truncate();
    }
}
