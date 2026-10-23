<?php

use PHPUnit\Framework\TestCase;
use App\Helpers\TableIndexHelper;

/**
 * The planning half of TableIndexHelper is pure and is tested here without a
 * database. (The SQL it produces was also run against a real MariaDB.)
 */
class TableIndexHelperUnitTest extends TestCase
{
    private function cfg(array $over = []) {
        return array_merge([
            'index_columns' => ['/_id$/'],
            'index_exclude' => ['/^user_id$/', '/^workflow_person_id$/'],
            'index_max' => 10,
        ], $over);
    }

    private function commTable() {
        return [
            'id' => 'int(10) unsigned', 'record_type' => 'varchar(30)', 'person_id' => 'int(11)',
            'communication_id' => 'int(11)', 'workflow_id' => 'varchar(30)', 'workflow_person_id' => 'varchar(30)',
            'user_id' => 'varchar(30)', 'address_id' => 'varchar(30)', 'household_id' => 'varchar(30)',
            'status' => 'varchar(30)', 'date_in' => 'int(11)', 'subject' => 'varchar(150)',
            'srchindex' => 'longtext', 'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        ];
    }

    public function testIdColumnsAreRecognisedByName() {
        $cfg = $this->cfg();
        foreach (['person_id', 'communication_id', 'address_id', 'constituent_id', 'casework_id'] as $c) {
            $this->assertTrue(TableIndexHelper::isIdColumn($c, $cfg), $c);
        }
        foreach (['status', 'date_in', 'id', 'identity', 'user_id', 'workflow_person_id', 'id_number'] as $c) {
            $this->assertFalse(TableIndexHelper::isIdColumn($c, $cfg), $c);
        }
    }

    public function testPlanPicksIdColumnsOfIndexableTypes() {
        $this->assertSame(
            ['person_id', 'communication_id', 'workflow_id', 'address_id', 'household_id'],
            TableIndexHelper::plan($this->commTable(), ['id'], $this->cfg())
        );
    }

    public function testPlanSkipsColumnsThatAreAlreadyIndexed() {
        $plan = TableIndexHelper::plan($this->commTable(), ['id', 'person_id', 'address_id'], $this->cfg());
        $this->assertSame(['communication_id', 'workflow_id', 'household_id'], $plan);
        $this->assertSame([], TableIndexHelper::plan($this->commTable(), ['id', 'person_id', 'communication_id', 'workflow_id', 'address_id', 'household_id'], $this->cfg()));
    }

    public function testPlanSkipsTextAndHugeVarcharColumns() {
        $cols = ['id' => 'int', 'long_id' => 'varchar(500)', 'note_id' => 'text', 'ok_id' => 'varchar(150)', 'big_id' => 'bigint(20)', 'dec_id' => 'decimal(10,2)'];
        $this->assertSame(['ok_id', 'big_id'], TableIndexHelper::plan($cols, [], $this->cfg()));
    }

    public function testPlanIsCappedByIndexMax() {
        $cols = [];
        for ($i = 0; $i < 20; $i++) {
            $cols["c{$i}_id"] = 'int(11)';
        }
        $this->assertCount(3, TableIndexHelper::plan($cols, [], $this->cfg(['index_max' => 3])));
    }

    public function testHeaderlessTablesGetNoIndexes() {
        $cols = ['id' => 'int', 'Field0' => 'varchar(30)', 'Field1' => 'int(11)', 'Field2' => 'varchar(30)'];
        $this->assertSame([], TableIndexHelper::plan($cols, [], $this->cfg()));
    }

    public function testAlterSqlIsOneStatementAndQuotesNames() {
        $sql = TableIndexHelper::alterSql('2A1790000000', ['person_id', 'address_id']);
        $this->assertSame('ALTER TABLE `2A1790000000` ADD INDEX `idx_person_id` (`person_id`), ADD INDEX `idx_address_id` (`address_id`)', $sql);
        $long = str_repeat('x', 70) . '_id';
        $this->assertLessThanOrEqual(64, strlen(substr('idx_' . $long, 0, 64)));
        $this->assertStringContainsString('`idx_' . substr($long, 0, 60) . '`', TableIndexHelper::alterSql('t', [$long]));
    }

    public function testMySql8TypeStringsWithoutDisplayWidthAreRecognised() {
        // MySQL 8.0.19+ reports "int" / "bigint unsigned" instead of "int(11)"
        foreach (['int', 'int unsigned', 'bigint unsigned', 'smallint', 'varchar(30)'] as $type) {
            $this->assertTrue(TableIndexHelper::isIndexableType($type), $type);
        }
        $this->assertFalse(TableIndexHelper::isIndexableType('longtext'));
        $this->assertSame(['person_id', 'address_id'], TableIndexHelper::plan(
            ['id' => 'int unsigned', 'person_id' => 'int', 'address_id' => 'bigint unsigned', 'state' => 'varchar(2)'], [], [
                'index_columns' => ['/_id$/'], 'index_exclude' => ['/^user_id$/'], 'index_max' => 10,
            ]));
    }
}
