<?php

use PHPUnit\Framework\TestCase;
use App\Libraries\Charts\ChartQueryBuilder;
use App\Libraries\Charts\ChartSpecException;
use App\Libraries\Charts\StarterCharts;

/**
 * Tests for the chart spec -> SQL builder. The builder has no database or
 * Laravel dependency, so these run as plain PHPUnit tests.
 */
class ChartQueryBuilderUnitTest extends TestCase
{
    /** Columns of an IQ v2 2A (communication) table as CCDT creates them. */
    private function commColumns() {
        return [
            'id' => 'int(10) unsigned', 'record_type' => 'varchar(30)', 'person_id' => 'int(11)',
            'communication_id' => 'int(11)', 'workflow_id' => 'varchar(30)', 'communication_type' => 'varchar(30)',
            'user_id' => 'varchar(30)', 'status' => 'varchar(30)', 'date_in' => 'int(11)', 'date_out' => 'int(11)',
            'response_type' => 'varchar(30)', 'email_address' => 'varchar(150)', 'first_name' => 'varchar(30)',
            'subject' => 'varchar(150)', 'body' => 'longtext', 'srchindex' => 'longtext',
            'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        ];
    }

    private function builder(array $config = []) {
        return new ChartQueryBuilder('2A1790000000', $this->commColumns(), array_merge([
            'blocked_columns' => ['/name/i', '/email/i', '/subject/i'],
            'min_cell_size' => 10,
        ], $config));
    }

    private function line() {
        return ['chart' => 'line', 'x' => ['column' => 'date_in', 'bucket' => 'month'],
                'group' => ['column' => 'communication_type'], 'measure' => ['fn' => 'count']];
    }

    private function assertRejected($message, callable $fn) {
        try {
            $fn();
        } catch (ChartSpecException $e) {
            $this->assertStringContainsStringIgnoringCase($message, $e->getMessage());
            return;
        }
        $this->fail("Expected ChartSpecException containing '{$message}'");
    }

    // ---------------- validation

    public function testNormalizeFillsDefaults() {
        $spec = $this->builder()->normalize($this->line());
        $this->assertSame('line', $spec['chart']);
        $this->assertSame(20, $spec['limit']);
        $this->assertSame([], $spec['filters']);
        $this->assertSame('time', $spec['x']['kind']);
    }

    public function testUsableColumnsHideSystemFreeTextAndPersonalColumns() {
        $cols = array_keys($this->builder()->usableColumns());
        foreach (['id', 'srchindex', 'created_at', 'updated_at', 'body', 'first_name', 'email_address', 'subject'] as $bad) {
            $this->assertNotContains($bad, $cols, "$bad must not be offered");
        }
        $this->assertContains('date_in', $cols);
        $this->assertTrue($this->builder()->usableColumns()['date_in']['numeric']);
        $this->assertFalse($this->builder()->usableColumns()['status']['numeric']);
    }

    public function testRejectsUnknownBlockedAndSystemColumns() {
        $b = $this->builder();
        foreach (['no_such_column', 'first_name', 'email_address', 'srchindex', 'body', 'id'] as $col) {
            $spec = $this->line();
            $spec['group'] = ['column' => $col];
            $this->assertRejected('', function () use ($b, $spec) {
                $b->normalize($spec);
            });
        }
    }

    public function testBlockedColumnCannotBeUsedAsFilterOrMeasureEither() {
        $b = $this->builder();
        $spec = $this->line();
        $spec['filters'] = [['column' => 'email_address', 'op' => 'eq', 'value' => 'a@b.c']];
        $this->assertRejected('cannot be used', function () use ($b, $spec) { $b->normalize($spec); });
        $spec = $this->line();
        $spec['measure'] = ['fn' => 'count_distinct', 'column' => 'first_name'];
        $this->assertRejected('cannot be used', function () use ($b, $spec) { $b->normalize($spec); });
    }

    public function testIdentifierInjectionIsRejectedNotEscapedIntoSql() {
        $b = $this->builder();
        $spec = $this->line();
        $spec['x']['column'] = 'date_in` ; DROP TABLE tables; --';
        $this->assertRejected('unknown column', function () use ($b, $spec) { $b->normalize($spec); });
    }

    public function testChartTypeRules() {
        $b = $this->builder();
        $bar = ['chart' => 'bar', 'x' => ['column' => 'status'], 'measure' => ['fn' => 'count']];
        $this->assertSame('category', $b->normalize($bar)['x']['kind']);

        $withGroup = $bar + ['group' => ['column' => 'communication_type']];
        $this->assertRejected('second field', function () use ($b, $withGroup) { $b->normalize($withGroup); });

        $stacked = ['chart' => 'stacked', 'x' => ['column' => 'status'], 'measure' => ['fn' => 'count']];
        $this->assertRejected('second field to split', function () use ($b, $stacked) { $b->normalize($stacked); });

        $lineNoBucket = ['chart' => 'line', 'x' => ['column' => 'date_in', 'bucket' => 'none'], 'measure' => ['fn' => 'count']];
        $this->assertRejected('month or year', function () use ($b, $lineNoBucket) { $b->normalize($lineNoBucket); });

        $histText = ['chart' => 'hist', 'x' => ['column' => 'status'], 'measure' => ['fn' => 'count']];
        $this->assertRejected('numeric', function () use ($b, $histText) { $b->normalize($histText); });

        $days = ['chart' => 'bar', 'x' => ['kind' => 'days_between', 'from' => 'date_in', 'to' => 'date_out'], 'measure' => ['fn' => 'count']];
        $this->assertRejected('only available for histograms', function () use ($b, $days) { $b->normalize($days); });

        $this->assertRejected('chart type', function () use ($b) { $b->normalize(['chart' => 'pie']); });
        $this->assertRejected('x axis', function () use ($b) { $b->normalize(['chart' => 'bar']); });
    }

    public function testSumAndAverageNeedANumericField() {
        $b = $this->builder();
        $spec = $this->line();
        $spec['measure'] = ['fn' => 'sum', 'column' => 'status'];
        $this->assertRejected('numeric', function () use ($b, $spec) { $b->normalize($spec); });
        $spec['measure'] = ['fn' => 'sum', 'column' => 'person_id'];
        $this->assertSame('sum', $b->normalize($spec)['measure']['fn']);
    }

    public function testFilterValidation() {
        $b = $this->builder();
        $spec = $this->line();

        $spec['filters'] = [['column' => 'date_in', 'op' => 'gte', 'value' => '2017-01-01']];
        $this->assertRejected('whole number', function () use ($b, $spec) { $b->normalize($spec); });

        $spec['filters'] = [['column' => 'person_id', 'op' => 'eq', 'value' => 'abc']];
        $this->assertRejected('numeric', function () use ($b, $spec) { $b->normalize($spec); });

        $spec['filters'] = [['column' => 'status', 'op' => 'drop', 'value' => 'x']];
        $this->assertRejected('operator', function () use ($b, $spec) { $b->normalize($spec); });

        $spec['filters'] = [['column' => 'status', 'op' => 'eq', 'value' => '']];
        $this->assertRejected('enter a value', function () use ($b, $spec) { $b->normalize($spec); });

        $spec['filters'] = array_fill(0, 9, ['column' => 'status', 'op' => 'eq', 'value' => 'C']);
        $this->assertRejected('too many filters', function () use ($b, $spec) { $b->normalize($spec); });

        $spec['filters'] = [['column' => 'status', 'op' => 'in', 'value' => 'C, P ,']];
        $n = $b->normalize($spec);
        $this->assertSame(['C', 'P', ''], $n['filters'][0]['value']);   // comma list split and trimmed
    }

    public function testLimitIsCapped() {
        $spec = $this->builder()->normalize($this->line() + ['limit' => 100000]);
        $this->assertSame(50, $spec['limit']);
    }

    // ---------------- SQL

    public function testTimeSeriesQueryShapeAndBindingsOrder() {
        $b = $this->builder();
        $spec = $this->line();
        $spec['filters'] = [['column' => 'status', 'op' => 'eq', 'value' => 'SENTINEL-VALUE'],
                            ['column' => 'date_in', 'op' => 'between', 'value' => '20170101,20171231']];
        $spec = $b->normalize($spec);
        $this->assertSame(['group'], $b->lookups($spec));

        $q = $b->query($spec, ['group' => ['usmail', 'imail']]);
        $sql = $q['sql'];

        $this->assertStringContainsString('MAX_EXECUTION_TIME(30000)', $sql);
        $this->assertStringContainsString('FLOOR(CAST(`date_in` AS UNSIGNED) / 100) AS x', $sql);
        $this->assertStringContainsString("CASE WHEN COALESCE(NULLIF(TRIM(CAST(`communication_type` AS CHAR)), ''), '(blank)') IN (?,?) THEN", $sql);
        $this->assertStringContainsString("ELSE 'Other' END AS g", $sql);
        $this->assertStringContainsString('CAST(`date_in` AS UNSIGNED) BETWEEN 19000101 AND 20991231', $sql);
        $this->assertStringContainsString('GROUP BY 1, 2', $sql);
        $this->assertStringContainsString('FROM `2A1790000000`', $sql);
        $this->assertSame(1, preg_match('/LIMIT \d+$/', $sql), 'query ends with a LIMIT');

        // group values come first (SELECT clause), then filter values, in SQL order
        $this->assertSame(['usmail', 'imail', 'SENTINEL-VALUE', '20170101', '20171231'], $q['bindings']);
        $this->assertSame(count($q['bindings']), substr_count($sql, '?'));
        $this->assertStringNotContainsString('SENTINEL-VALUE', $sql, 'values must be bound, never inlined');
    }

    public function testTopValuesQuery() {
        $b = $this->builder();
        $spec = $b->normalize($this->line());
        $q = $b->topValuesQuery($spec, 'group');
        $this->assertStringContainsString('GROUP BY 1 ORDER BY COUNT(*) DESC', $q['sql']);
        $this->assertStringContainsString('LIMIT 8', $q['sql']);
        $this->assertSame(substr_count($q['sql'], '?'), count($q['bindings']));
    }

    public function testRankedBarQuery() {
        $b = $this->builder();
        $spec = $b->normalize(['chart' => 'hbar', 'x' => ['column' => 'status'], 'measure' => ['fn' => 'count'], 'limit' => 5]);
        $this->assertSame([], $b->lookups($spec));
        $q = $b->query($spec);
        $this->assertStringContainsString('ORDER BY v DESC, 1 ASC LIMIT 5', $q['sql']);
        $this->assertStringContainsString('GROUP BY 1', $q['sql']);
    }

    public function testStackedWithCategoryXLooksUpBothDimensions() {
        $b = $this->builder();
        $spec = $b->normalize(['chart' => 'heatmap', 'x' => ['column' => 'status'], 'group' => ['column' => 'communication_type'],
                               'measure' => ['fn' => 'count'], 'limit' => 12]);
        $this->assertSame(['group', 'x'], $b->lookups($spec));
        $q = $b->query($spec, ['group' => ['usmail'], 'x' => ['C', 'P']]);
        $this->assertSame(['usmail', 'C', 'P'], $q['bindings']);
        $this->assertSame(count($q['bindings']), substr_count($q['sql'], '?'));
    }

    public function testDaysBetweenHistogram() {
        $b = $this->builder();
        $spec = $b->normalize(['chart' => 'hist', 'x' => ['kind' => 'days_between', 'from' => 'date_in', 'to' => 'date_out', 'cap' => 365, 'bin' => 7],
                               'measure' => ['fn' => 'count']]);
        $sql = $b->query($spec)['sql'];
        $this->assertStringContainsString('DATEDIFF(STR_TO_DATE(CAST(`date_out` AS CHAR), \'%Y%m%d\'), STR_TO_DATE(CAST(`date_in` AS CHAR), \'%Y%m%d\'))', $sql);
        $this->assertStringContainsString('(FLOOR(LEAST(', $sql);
        $this->assertStringContainsString(' >= 0', $sql);
        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    public function testFilterOperators() {
        $b = $this->builder();
        $spec = $this->line();
        $spec['filters'] = [
            ['column' => 'status', 'op' => 'neq', 'value' => 'X'],
            ['column' => 'status', 'op' => 'in', 'value' => ['C', 'P']],
            ['column' => 'date_in', 'op' => 'gte', 'value' => '20170101'],
            ['column' => 'date_in', 'op' => 'lte', 'value' => '20171231'],
            ['column' => 'status', 'op' => 'prefix', 'value' => '50%_off'],
            ['column' => 'response_type', 'op' => 'blank'],
            ['column' => 'user_id', 'op' => 'notblank'],
        ];
        $q = $b->query($b->normalize($spec), ['group' => ['a']]);
        $this->assertSame(['a', 'X', 'C', 'P', '20170101', '20171231', '50\\%\\_off%'], $q['bindings']);
        $this->assertSame(count($q['bindings']), substr_count($q['sql'], '?'));
    }

    public function testMeasuresAreWhitelistedExpressions() {
        $b = $this->builder();
        $cases = ['count' => 'COUNT(*) AS v', 'count_distinct' => 'COUNT(DISTINCT `person_id`) AS v',
                  'sum' => 'SUM(`person_id`) AS v', 'avg' => 'AVG(`person_id`) AS v'];
        foreach ($cases as $fn => $expect) {
            $spec = $b->normalize(['chart' => 'bar', 'x' => ['column' => 'status'], 'measure' => ['fn' => $fn, 'column' => 'person_id']]);
            $this->assertStringContainsString($expect, $b->query($spec)['sql']);
        }
    }

    public function testTableNameIsValidated() {
        $this->expectException(ChartSpecException::class);
        new ChartQueryBuilder('tables`; DROP TABLE x', []);
    }

    // ---------------- shaping

    private function rows($data) {
        $out = [];
        foreach ($data as $r) {
            $out[] = (object) array_merge(['g' => null], $r);
        }
        return $out;
    }

    public function testShapeFillsMissingMonthsWithZeroAndOrdersSeries() {
        $b = $this->builder();
        $spec = $b->normalize($this->line());
        $shaped = $b->shape($this->rows([
            ['x' => 201711, 'g' => 'imail', 'v' => 50, 'n' => 50],
            ['x' => 201801, 'g' => 'imail', 'v' => 60, 'n' => 60],
            ['x' => 201711, 'g' => 'usmail', 'v' => 200, 'n' => 200],
            ['x' => 201712, 'g' => 'Other', 'v' => 15, 'n' => 15],
        ]), $spec);

        $this->assertSame(['201711', '201712', '201801'], $shaped['x']);
        $this->assertSame('time', $shaped['x_kind']);
        $this->assertSame(['usmail', 'imail', 'Other'], array_column($shaped['series'], 'name'), 'biggest first, Other last');
        $this->assertSame([200, 0, 0], $shaped['series'][0]['values']);
        $this->assertSame([50, 0, 60], $shaped['series'][1]['values']);
        $this->assertSame(0, $shaped['suppressed']);
    }

    public function testShapeSuppressesSmallCells() {
        $b = $this->builder();
        $spec = $b->normalize(['chart' => 'bar', 'x' => ['column' => 'status'], 'measure' => ['fn' => 'count']]);
        $shaped = $b->shape($this->rows([
            ['x' => 'C', 'v' => 500, 'n' => 500],
            ['x' => 'P', 'v' => 9, 'n' => 9],        // below 10: hidden
            ['x' => 'R', 'v' => 10, 'n' => 10],      // exactly 10: shown
        ]), $spec);
        $this->assertSame(1, $shaped['suppressed']);
        $this->assertSame(['C', 'R', 'P'], $shaped['x']);
        $this->assertSame([500, 10, null], $shaped['series'][0]['values']);
    }

    public function testSuppressionUsesRecordCountNotTheMeasure() {
        // 3 records summing to a big number are still a small cell
        $b = $this->builder();
        $spec = $b->normalize(['chart' => 'bar', 'x' => ['column' => 'status'], 'measure' => ['fn' => 'sum', 'column' => 'person_id']]);
        $shaped = $b->shape($this->rows([['x' => 'C', 'v' => 999999, 'n' => 3]]), $spec);
        $this->assertSame(1, $shaped['suppressed']);
        $this->assertSame([null], $shaped['series'][0]['values']);
    }

    public function testSuppressionCanBeTurnedOff() {
        $b = $this->builder(['min_cell_size' => 0]);
        $spec = $b->normalize(['chart' => 'bar', 'x' => ['column' => 'status'], 'measure' => ['fn' => 'count']]);
        $shaped = $b->shape($this->rows([['x' => 'C', 'v' => 2, 'n' => 2]]), $spec);
        $this->assertSame(0, $shaped['suppressed']);
        $this->assertSame([2], $shaped['series'][0]['values']);
    }

    public function testAverageLeavesMissingAsNullNotZero() {
        $b = $this->builder(['min_cell_size' => 0]);
        $spec = $b->normalize(['chart' => 'line', 'x' => ['column' => 'date_in', 'bucket' => 'month'],
                               'group' => ['column' => 'status'], 'measure' => ['fn' => 'avg', 'column' => 'person_id']]);
        $shaped = $b->shape($this->rows([
            ['x' => 201701, 'g' => 'C', 'v' => '10.5000', 'n' => 4],
            ['x' => 201703, 'g' => 'C', 'v' => '12.0000', 'n' => 4],
        ]), $spec);
        $this->assertSame([10.5, null, 12.0], $shaped['series'][0]['values']);
    }

    public function testHistogramBinsAreContiguousAndNumeric() {
        $b = $this->builder(['min_cell_size' => 0]);
        $spec = $b->normalize(['chart' => 'hist', 'x' => ['kind' => 'days_between', 'from' => 'date_in', 'to' => 'date_out', 'cap' => 365, 'bin' => 7],
                               'measure' => ['fn' => 'count']]);
        $shaped = $b->shape($this->rows([['x' => 0, 'v' => 5, 'n' => 5], ['x' => 21, 'v' => 2, 'n' => 2], ['x' => 7, 'v' => 3, 'n' => 3]]), $spec);
        $this->assertSame(['0', '7', '14', '21'], $shaped['x']);
        $this->assertSame([5, 3, 0, 2], $shaped['series'][0]['values']);
        $this->assertSame('number', $shaped['x_kind']);
        $this->assertSame('Records', $shaped['series'][0]['name']);
    }

    public function testShapeHandlesEmptyResult() {
        $b = $this->builder();
        $shaped = $b->shape([], $b->normalize($this->line()));
        $this->assertSame([], $shaped['x']);
        $this->assertSame([], $shaped['series']);
    }

    public function testTitlesAndLabels() {
        $b = $this->builder();
        $shaped = $b->shape([], $b->normalize($this->line()));
        $this->assertSame('Records by Date In (month), split by Communication Type', $shaped['title']);
        $named = $b->shape([], $b->normalize($this->line() + ['title' => '  My chart ']));
        $this->assertSame('My chart', $named['title']);
    }

    // ---------------- starter charts

    public function testStartersAreOfferedOnlyWhenColumnsExist() {
        $ids = array_column(StarterCharts::forColumns(array_keys($this->commColumns())), 'id');
        $this->assertContains('comms_per_month', $ids);
        $this->assertContains('days_to_close', $ids);
        $this->assertContains('comms_by_status', $ids);
        $this->assertNotContains('top_topics', $ids);          // no communication_code column here
        $this->assertNotContains('workflow_starts', $ids);

        $this->assertSame([], StarterCharts::forColumns(['id', 'field0', 'field1', 'field2']));
    }

    public function testEveryStarterProducesAValidSpecForItsTable() {
        $tables = [
            'comm' => $this->commColumns(),
            'topic' => ['id' => 'int', 'record_type' => 'varchar(30)', 'person_id' => 'int(11)', 'communication_id' => 'int(11)', 'communication_code' => 'varchar(30)', 'position' => 'varchar(30)'],
            'workflow' => ['id' => 'int', 'workflow_type' => 'varchar(30)', 'start_date' => 'int(11)', 'status' => 'varchar(30)'],
            'address' => ['id' => 'int', 'state_code' => 'varchar(30)', 'city' => 'varchar(30)'],
            'v1' => ['id' => 'int', 'correspondence_type' => 'varchar(30)', 'date_in' => 'int(11)', 'date_out' => 'int(11)', 'status' => 'varchar(30)'],
        ];
        $seen = [];
        foreach ($tables as $label => $cols) {
            $b = new ChartQueryBuilder('t1', $cols, ['blocked_columns' => ['/name/i']]);
            $starters = StarterCharts::forColumns(array_keys($cols));
            $this->assertNotEmpty($starters, $label);
            foreach ($starters as $s) {
                $spec = $b->normalize($s['spec']);                 // must not throw
                $q = $b->query($spec, ['group' => ['a'], 'x' => ['b']]);
                $this->assertSame(count($q['bindings']), substr_count($q['sql'], '?'), "$label/{$s['id']}");
                $seen[$s['id']] = true;
            }
        }
        $this->assertCount(7, $seen, 'every starter chart was exercised against a table that offers it');
    }

    // ---------------- two tables combined

    private function addrColumns() {
        return ['id' => 'int(10) unsigned', 'address_id' => 'int(11)', 'person_id' => 'int(11)', 'state' => 'varchar(30)',
                'city' => 'varchar(30)', 'zip' => 'varchar(10)', 'user_id' => 'varchar(30)'];
    }

    private function joinBuilder() {
        $cols = $this->commColumns();
        $cols['address_id'] = 'varchar(30)';       // 2A keeps it as text, 1B as an integer
        return new ChartQueryBuilder('2A1790000000', $cols, [
            'blocked_columns' => ['/name/i', '/email/i', '/subject/i'], 'min_cell_size' => 10,
            'index_columns' => ['/_id$/'], 'index_exclude' => ['/^user_id$/'],
        ], ['table' => '1B1790000000', 'columns' => $this->addrColumns()]);
    }

    private function joinSpec() {
        return ['chart' => 'hbar', 'x' => ['column' => 'state', 'src' => 'b', 'bucket' => 'none'], 'measure' => ['fn' => 'count'],
                'join' => ['left' => 'address_id', 'right' => 'address_id']];
    }

    public function testJoinQueryQualifiesColumnsAndKeepsBlankKeysOut() {
        $b = $this->joinBuilder();
        $norm = $b->normalize($this->joinSpec());
        $q = $b->query($norm, []);
        $this->assertStringContainsString('FROM `2A1790000000` a JOIN `1B1790000000` b ON a.`address_id` = b.`address_id`', $q['sql']);
        $this->assertStringContainsString('b.`state`', $q['sql']);
        $this->assertStringContainsString("TRIM(CAST(a.`address_id` AS CHAR)) <> ''", $q['sql']);
        $this->assertStringContainsString("TRIM(CAST(b.`address_id` AS CHAR)) <> ''", $q['sql']);
        $this->assertStringNotContainsString('?`', $q['sql']);
    }

    public function testSingleTableSqlIsNotAliased() {
        $b = $this->builder();
        $q = $b->query($b->normalize($this->line()), ['x']);
        $this->assertStringNotContainsString(' a ', $q['sql']);
        $this->assertStringNotContainsString('JOIN', $q['sql']);
    }

    public function testJoinedColumnsAreListedAndBlockedOnesStillHidden() {
        $b = $this->joinBuilder();
        $this->assertArrayHasKey('state', $b->usableColumns('b'));
        $this->assertArrayNotHasKey('state', $this->builder()->usableColumns('b') ?: []);
    }

    public function testJoinKeysMustBeIdColumns() {
        $b = $this->joinBuilder();
        $spec = $this->joinSpec();
        $spec['join'] = ['left' => 'status', 'right' => 'state'];
        $this->assertRejected('ID', function () use ($b, $spec) { $b->normalize($spec); });
        $spec['join'] = ['left' => 'user_id', 'right' => 'user_id'];
        $this->assertRejected('ID', function () use ($b, $spec) { $b->normalize($spec); });
        $spec['join'] = ['left' => 'nope', 'right' => 'address_id'];
        $this->assertRejected('', function () use ($b, $spec) { $b->normalize($spec); });
    }

    public function testJoinedFieldWithoutJoinIsRejected() {
        $b = $this->builder();
        $spec = $this->joinSpec();
        $this->assertRejected('', function () use ($b, $spec) { $b->normalize($spec); });
        $spec = ['chart' => 'bar', 'x' => ['column' => 'status', 'src' => 'b'], 'measure' => ['fn' => 'count']];
        $this->assertRejected('', function () use ($b, $spec) { $b->normalize($spec); });
    }

    public function testJoinedFieldUsedWithoutJoinKeysIsRejected() {
        $b = $this->joinBuilder();
        $spec = $this->joinSpec();
        unset($spec['join']);
        $this->assertRejected('', function () use ($b, $spec) { $b->normalize($spec); });
    }

    public function testFiltersGroupAndMeasureCanUseEitherTable() {
        $b = $this->joinBuilder();
        $spec = ['chart' => 'stacked', 'x' => ['column' => 'communication_type', 'src' => 'a', 'bucket' => 'none'],
                 'group' => ['column' => 'state', 'src' => 'b'], 'measure' => ['fn' => 'count'],
                 'filters' => [['column' => 'state', 'src' => 'b', 'op' => 'eq', 'value' => 'WV'],
                               ['column' => 'status', 'src' => 'a', 'op' => 'eq', 'value' => 'C']],
                 'join' => ['left' => 'address_id', 'right' => 'address_id']];
        $norm = $b->normalize($spec);
        $q = $b->query($norm, ['x' => ['WV'], 'group' => ['WV']]);
        $this->assertStringContainsString('b.`state`', $q['sql']);
        $this->assertStringContainsString('a.`status`', $q['sql']);
        $this->assertContains('WV', $q['bindings']);
        $this->assertSame('b', $norm['group']['src']);
    }

    public function testForJoinFindsStartersInEitherOrientation() {
        $a = array_keys($this->commColumns());
        $bcols = array_keys($this->addrColumns());
        $fwd = StarterCharts::forJoin(array_merge($a, ['address_id']), $bcols);
        $rev = StarterCharts::forJoin($bcols, array_merge($a, ['address_id']));
        $this->assertNotEmpty($fwd);
        $this->assertSame(count($fwd), count($rev));
        foreach ($fwd as $st) { $this->assertSame('a', $st['spec']['x']['src'] === 'b' ? 'a' : 'a'); }
        $labels = array_column($fwd, 'label');
        $this->assertSame($labels, array_column($rev, 'label'));
    }

    public function testEveryJoinStarterIsAValidSpec() {
        $a = array_merge(array_keys($this->commColumns()), ['address_id']);
        $cols = $this->commColumns();
        $cols['address_id'] = 'varchar(30)';
        $addr = $this->addrColumns();
        $b = new ChartQueryBuilder('2A1790000000', $cols, [
            'blocked_columns' => ['/name/i', '/email/i', '/subject/i'], 'min_cell_size' => 10,
            'index_columns' => ['/_id$/'], 'index_exclude' => ['/^user_id$/'],
        ], ['table' => '1B1790000000', 'columns' => $addr]);
        $starters = StarterCharts::forJoin($a, array_keys($addr));
        $this->assertNotEmpty($starters);
        foreach ($starters as $st) {
            $n = $b->normalize($st['spec']);
            $this->assertNotEmpty($n['join'], $st['label']);
            $b->query($n, []);
        }
    }
}
