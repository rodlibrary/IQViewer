<?php

use PHPUnit\Framework\TestCase;
use App\Helpers\CSVHelper;
use App\Helpers\LargeImportHelper;
use App\Helpers\LargeImportScan;

/**
 * The pure parts of import:large-dataset: scanning a mixed file for layouts and
 * column types, row normalizing, table and column names, search index text.
 * (No database or Laravel needed.)
 */
class LargeImportHelperTest extends TestCase
{
    private $layout2a = ['Record Type', 'Constituent ID', 'Correspondence ID', 'Correspondence Type', 'Staff', 'Date In', 'Zip'];

    private function finder() {
        $layouts = ['2A' => $this->layout2a];
        return function ($type, $count) use ($layouts) {
            return isset($layouts[$type]) && count($layouts[$type]) >= $count ? $layouts[$type] : null;
        };
    }

    private function types(array $plan) {
        return (new CSVHelper)->fieldTypesFromCheck($plan['check']);
    }

    // ---------------- scanning and typing

    public function testIntegerColumnsStayIntegerAndTextColumnsBecomeText() {
        $scan = new LargeImportScan();
        $scan->add('2A', ['2A', '1000001', '5000001', 'usmail', 'JSMITH', '20190304', '02134']);
        $scan->add('2A', ['2A', '1000002', '5000002', 'phone', 'JSMITH', '20190305', '26505']);
        $plan = $scan->resolve('2A', $this->finder());
        $t = $this->types($plan);

        $this->assertSame(7, $plan['count']);
        $this->assertSame('integer', $t[1][0]);          // constituent id
        $this->assertSame('integer', $t[2][0]);          // correspondence id
        $this->assertSame('string', $t[3][0]);
        $this->assertSame('integer', $t[5][0]);          // date in
        $this->assertSame('string', $t[6][0], 'a zip code with a leading zero must stay text');
    }

    public function testOneBadValueMakesTheWholeColumnTextEvenIfTheLastRowIsNumeric() {
        $scan = new LargeImportScan();
        $scan->add('2A', ['2A', '1', '1', 'x', 's', '20190101', '1']);
        $scan->add('2A', ['2A', 'QC', '2', 'x', 's', '20190102', '2']);
        $scan->add('2A', ['2A', '3', '3', 'x', 's', '20190103', '3']);
        $t = $this->types($scan->resolve('2A', $this->finder()));
        $this->assertSame('string', $t[1][0]);
        $this->assertSame('integer', $t[2][0]);
    }

    public function testPlaceholdersAndBlanksMakeAColumnText() {
        $scan = new LargeImportScan();
        $scan->add('2A', ['2A', '1', '1', 'x', '<PERSON>', '20190101', '1']);
        $scan->add('2A', ['2A', '2', '2', 'x', 'JS', '', '2']);
        $t = $this->types($scan->resolve('2A', $this->finder()));
        $this->assertSame('string', $t[4][0]);
        $this->assertSame('string', $t[5][0], 'a blank date makes the column text');
    }

    public function testBigNumbersGetBigintAndLongTextGetsLargerTypes() {
        $scan = new LargeImportScan();
        $scan->add('2A', ['2A', '9999999999', '1', str_repeat('a', 200), 's', '20190101', '1']);
        $t = $this->types($scan->resolve('2A', $this->finder()));
        $this->assertSame(['integer', 'big'], [$t[1][0], $t[1][1]]);
        $this->assertSame(['string', 'big'], [$t[3][0], $t[3][1]]);
    }

    public function testShortRowsArePaddedAndTheirMissingColumnsBecomeText() {
        $scan = new LargeImportScan();
        for ($i = 1; $i <= 5; $i++) {
            $scan->add('2A', ['2A', (string) $i, (string) $i, 'x', 's', '2019010' . $i, (string) (10000 + $i)]);
        }
        $scan->add('2A', ['2A', '6', '6', 'x']);          // trailing fields dropped by the export
        $plan = $scan->resolve('2A', $this->finder());
        $t = $this->types($plan);

        $this->assertSame(1, $plan['padded']);
        $this->assertSame(0, $plan['folded']);
        $this->assertSame('integer', $t[1][0]);
        $this->assertSame('string', $t[4][0]);
        $this->assertSame('string', $t[6][0]);
    }

    public function testUsualCountDecidesTheLayoutAndLongRowsAreFoldedIntoTheLastColumn() {
        $scan = new LargeImportScan();
        for ($i = 1; $i <= 4; $i++) {
            $scan->add('2A', ['2A', (string) $i, (string) $i, 'x', 's', '20190101', 'z']);
        }
        $scan->add('2A', ['2A', '5', '5', 'x', 's', '20190101', 'one', 'two']);   // a "|" inside the last value
        $plan = $scan->resolve('2A', $this->finder());
        $this->assertSame(7, $plan['count']);
        $this->assertSame(1, $plan['folded']);
        $this->assertSame('string', $this->types($plan)[6][0]);
        $this->assertGreaterThanOrEqual(strlen('one|two'), $this->types($plan)[6][2]);

        $this->assertSame(['2A', '5', '5', 'x', 's', '20190101', 'one|two'],
            LargeImportHelper::normalize(['2A', '5', '5', 'x', 's', '20190101', 'one', 'two'], 7, '|'));
    }

    public function testUnknownRecordTypeGetsGeneratedFieldNames() {
        $scan = new LargeImportScan();
        $scan->add('ZZ', ['ZZ', 'a', '5']);
        $scan->add('ZZ', ['ZZ', 'b', '6']);
        $plan = $scan->resolve('ZZ', $this->finder());
        $this->assertFalse($plan['known']);
        $this->assertSame(['Field0', 'Field1', 'Field2'], $plan['header']);
        $this->assertSame('integer', $this->types($plan)[2][0]);
    }

    public function testLayoutWithMoreFieldsThanTheRowsHaveIsUsedAndPadded() {
        $scan = new LargeImportScan();
        $scan->add('2A', ['2A', '1', '1', 'x']);
        $scan->add('2A', ['2A', '2', '2', 'y']);
        $plan = $scan->resolve('2A', $this->finder());
        $this->assertSame(7, $plan['count']);
        $this->assertTrue($plan['known']);
        $this->assertSame('string', $this->types($plan)[6][0]);
    }

    public function testLayoutShorterThanTheRowsKeepsItsNamesAndGeneratesTheRest() {
        $scan = new LargeImportScan();
        $scan->add('2A', ['2A', '1', '1', 'x', 's', '20190101', 'z', '9', 'a']);
        $scan->add('2A', ['2A', '2', '2', 'x', 's', '20190101', 'z', '8', 'b']);
        $plan = $scan->resolve('2A', function ($type, $count) { return ['Record Type', 'Constituent ID', 'Correspondence ID']; });
        $this->assertSame(9, $plan['count']);
        $this->assertSame(6, $plan['extended']);
        $this->assertTrue($plan['known']);
        $this->assertSame(['Record Type', 'Constituent ID', 'Correspondence ID', 'Field3', 'Field4', 'Field5', 'Field6', 'Field7', 'Field8'], $plan['header']);
        $this->assertSame(0, $plan['folded']);
        $this->assertSame('integer', $this->types($plan)[7][0]);
    }

    public function testRecordTypesAreKeptSeparate() {
        $scan = new LargeImportScan();
        $scan->add('2A', ['2A', '1', '1', 'x', 's', '20190101', 'z']);
        $scan->add('ZZ', ['ZZ', '1']);
        $scan->add('2A', ['2A', '2', '2', 'x', 's', '20190101', 'z']);
        $this->assertSame(['2A', 'ZZ'], $scan->recordTypes());
        $this->assertSame(2, $scan->rowCount('2A'));
        $this->assertSame(1, $scan->rowCount('ZZ'));
    }

    // ---------------- lines, names, search text

    public function testTokensSkipBlankAndSeparatorLinesAndTrimFields() {
        $this->assertNull(LargeImportHelper::tokens("\r\n", '|'));
        $this->assertNull(LargeImportHelper::tokens("*****\r\n", '|'));
        $this->assertNull(LargeImportHelper::tokens("not a record|1\n", '|'));
        $this->assertSame(['2A', '12', 'x y', ''], LargeImportHelper::tokens("2A| 12 |x y|\r\n", '|'));
        $this->assertSame(['1A', 'b'], LargeImportHelper::tokens("1A\tb\n", "\t"));
    }

    public function testLatin1LinesAreConvertedToUtf8() {
        $tokens = LargeImportHelper::tokens("1A|Mu\xF1oz\n", '|');
        $this->assertSame('Muñoz', $tokens[1]);
        $tokens = LargeImportHelper::tokens("1A|Muñoz\n", '|');      // already UTF-8
        $this->assertSame('Muñoz', $tokens[1]);
    }

    public function testTableAndColumnNames() {
        $this->assertSame('2a_my_cases', LargeImportHelper::tableName('My Cases!', '2A'));
        $this->assertSame('1b_import', LargeImportHelper::tableName('', '1B'));
        $this->assertLessThanOrEqual(64, strlen(LargeImportHelper::tableName(str_repeat('x', 100), '2A')));

        $format = function ($s) { return strtolower(preg_replace('/[^A-Za-z0-9\-_]/', '', str_replace(' ', '_', $s))); };
        $this->assertSame(['record_type', 'constituent_id', 'id_field', 'srchindex_field', 'date_in', 'date_in_2', 'field6'],
            LargeImportHelper::columnNames(['Record Type', 'Constituent ID', 'ID', 'srchindex', 'Date In', 'Date In', '***'], $format));
    }

    public function testSearchIndexLeavesOutRecordTypeDatesFlagsAndIds() {
        $header = ['Record Type', 'Constituent ID', 'Individual Type', 'First Name', 'Date of Birth', 'No Mail Flag', 'Address ID'];
        $values = ['1A', '1000001', 'Person', 'Ann', '19800101', 'N', '77'];
        $this->assertSame('1000001 Person Ann', LargeImportHelper::searchIndex($header, $values, true));
        // unknown layout: everything after the record type
        $this->assertSame('a 5', LargeImportHelper::searchIndex(['Field0', 'Field1', 'Field2'], ['ZZ', 'a', '5'], false));
    }

    public function testFieldTypesFromCheckMatchesWhatCheckFileUsedToDo() {
        $csv = new CSVHelper();
        $this->assertSame([['integer', 'default', 7], ['integer', 'big', 10], ['string', 'default', 5], ['string', 'medium', 40], ['text', 'default', 600], ['string', 'default', 0]],
            $csv->fieldTypesFromCheck([[1, 7, false], [1, 10, false], [0, 5, true], [0, 40, true], [0, 600, true], [0, 0, false]]));
    }
}
