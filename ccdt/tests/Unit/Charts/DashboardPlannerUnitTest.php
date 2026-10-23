<?php

use PHPUnit\Framework\TestCase;
use App\Libraries\Dashboard\DashboardPlanner;

/**
 * The pure part of the data dashboard: which columns are counted how, the
 * headline numbers, and freshness. (No database or Laravel needed.)
 */
class DashboardPlannerUnitTest extends TestCase
{
    private function usable() {
        $v = function ($n = 30) { return ['type' => "varchar($n)", 'numeric' => false, 'id' => false]; };
        return [
            'person_id'          => ['type' => 'int(11)', 'numeric' => true, 'id' => true],
            'address_id'         => ['type' => 'int(11)', 'numeric' => true, 'id' => true],
            'gender'             => $v(),
            'city'               => $v(150),
            'state'              => $v(),
            'zip_code'           => ['type' => 'int(11)', 'numeric' => true, 'id' => false],
            'communication_type' => $v(),
            'status'             => $v(),
            'staff'              => $v(),
            'date_in'            => ['type' => 'int(11)', 'numeric' => true, 'id' => false],
            'tickler_date'       => $v(),
            'sequence'           => ['type' => 'int(11)', 'numeric' => true, 'id' => false],   // number, no name hint: not a category
            'salutation_code'    => $v(500),                                                     // too long to be a category
            'notes'              => $v(),
        ];
    }

    public function testNameWordsDecideTheGroup() {
        $this->assertTrue(DashboardPlanner::isDistributionName('state_code'));
        $this->assertTrue(DashboardPlanner::isDistributionName('zip'));
        $this->assertFalse(DashboardPlanner::isDistributionName('status'));      // "state" must not match "status"
        $this->assertFalse(DashboardPlanner::isDistributionName('package'));     // whole words only: not "age"
        $this->assertTrue(DashboardPlanner::isCodeName('communication_type'));
        $this->assertTrue(DashboardPlanner::isDateName('Date_In'));
        $this->assertFalse(DashboardPlanner::isDateName('update_flag'));         // "update" is not "date"
    }

    public function testCandidatesSplitIdsDatesAndCategories() {
        $c = (new DashboardPlanner)->candidates($this->usable());
        $this->assertSame(['person_id', 'address_id'], $c['ids']);
        $this->assertSame(['date_in', 'tickler_date'], $c['dates']);
        $this->assertContains('gender', $c['categorical']);
        $this->assertContains('zip_code', $c['categorical'], 'a whole-number column named zip is a category');
        $this->assertNotContains('sequence', $c['categorical'], 'a plain number is not a category');
        $this->assertNotContains('salutation_code', $c['categorical'], 'long text is not a category');
        $this->assertNotContains('date_in', $c['categorical']);
        // who/where first, then types and codes, then the rest
        $this->assertSame('gender', $c['categorical'][0]);
        $this->assertLessThan(array_search('communication_type', $c['categorical']), array_search('city', $c['categorical']));
    }

    public function testPlanKeepsUsefulColumnsAndGroupsThem() {
        $p = new DashboardPlanner();
        $c = $p->candidates($this->usable());
        $plan = $p->plan($c, [
            'gender' => 3, 'city' => 900, 'state' => 5, 'zip_code' => 1200, 'communication_type' => 6, 'status' => 1,
            'staff' => 30, 'notes' => 90000,
        ], 100000, ['date_in' => [20150103, 20260922], 'tickler_date' => null]);

        $by = [];
        foreach ($plan['breakdowns'] as $b) { $by[$b['column']] = $b['group']; }
        $this->assertSame('distribution', $by['gender']);
        $this->assertSame('distribution', $by['city'], 'many cities are fine for a named column');
        $this->assertSame('distribution', $by['zip_code']);
        $this->assertSame('code', $by['communication_type']);
        $this->assertSame('code', $by['staff']);
        $this->assertArrayNotHasKey('status', $by, 'one distinct value: nothing to show');
        $this->assertArrayNotHasKey('notes', $by, 'nearly every row differs');
        $this->assertSame(['person_id', 'address_id'], $plan['ids']);
        $this->assertSame([['column' => 'date_in', 'bucket' => 'year']], $plan['trends'], 'tickler_date holds no valid dates');
    }

    public function testAnUnnamedColumnNeedsFewDistinctValues() {
        $p = new DashboardPlanner();
        $c = ['ids' => [], 'dates' => [], 'categorical' => ['color_a', 'color_b']];
        $plan = $p->plan($c, ['color_a' => 12, 'color_b' => 800], 100000, []);
        $cols = array_column($plan['breakdowns'], 'column');
        $this->assertSame(['color_a'], $cols);
        $this->assertSame('other', $plan['breakdowns'][0]['group']);
    }

    public function testSmallTablesAreJudgedOnTheirOwnRows() {
        $p = new DashboardPlanner();
        $c = ['ids' => [], 'dates' => [], 'categorical' => ['kind_x']];
        // 5 distinct values in 6 rows is an identifier-like column only when the table is big enough to tell
        $this->assertCount(1, $p->plan($c, ['kind_x' => 5], 6, [])['breakdowns']);
        $this->assertCount(0, $p->plan($c, ['kind_x' => 90], 100, [])['breakdowns']);
    }

    public function testCapsPerGroup() {
        $p = new DashboardPlanner(['max_code' => 2, 'max_trends' => 1]);
        $cols = ['a_type', 'b_type', 'c_type'];
        $plan = $p->plan(['ids' => [], 'dates' => ['d_date', 'e_date'], 'categorical' => $cols],
            ['a_type' => 3, 'b_type' => 3, 'c_type' => 3], 1000, ['d_date' => [20200101, 20200301], 'e_date' => [20200101, 20200301]]);
        $this->assertCount(2, $plan['breakdowns']);
        $this->assertCount(1, $plan['trends']);
        $this->assertSame('month', $plan['trends'][0]['bucket'], 'a span under three years is shown by month');
    }

    public function testLabelsSpellIdInCapitals() {
        $this->assertSame('Person ID', DashboardPlanner::label('person_id'));
        $this->assertSame('Date In', DashboardPlanner::label('date_in'));
        $this->assertSame('Valid Identity', DashboardPlanner::label('valid_identity'), 'only the whole word Id is changed');
    }

    public function testBucketFollowsTheDateSpan() {
        $this->assertSame('year', DashboardPlanner::bucketFor(20100101, 20260101));
        $this->assertSame('year', DashboardPlanner::bucketFor(20230101, 20260101));
        $this->assertSame('month', DashboardPlanner::bucketFor(20230101, 20251231));
    }

    public function testSpecsUseOnlyWhatTheChartBuilderAccepts() {
        $p = new DashboardPlanner(['top_values' => 12]);
        $this->assertSame(['chart' => 'hbar', 'x' => ['column' => 'state', 'bucket' => 'none'], 'measure' => ['fn' => 'count'], 'limit' => 12], $p->breakdownSpec('state'));
        $this->assertSame(['chart' => 'line', 'x' => ['column' => 'date_in', 'bucket' => 'year'], 'measure' => ['fn' => 'count']], $p->trendSpec('date_in', 'year'));
    }

    public function testFreshness() {
        $this->assertTrue(DashboardPlanner::isFresh('2026-10-23 10:00:00', '2026-10-23 10:00:00'));
        $this->assertTrue(DashboardPlanner::isFresh('2026-10-23 10:00:00', '2026-10-23 10:00:00.123456'));
        $this->assertFalse(DashboardPlanner::isFresh('2026-10-23 10:00:00', '2026-10-23 10:05:00'));
        $this->assertFalse(DashboardPlanner::isFresh(null, '2026-10-23 10:00:00'));
    }

    public function testOverviewTotalsAndPeople() {
        $items = [
            ['table_id' => 1, 'name' => '1a_x', 'collection_id' => 1, 'collection' => 'Alpha', 'rows' => 1000, 'ids' => [['column' => 'person_id', 'count' => 900]]],
            ['table_id' => 2, 'name' => '2a_x', 'collection_id' => 1, 'collection' => 'Alpha', 'rows' => 5000, 'ids' => [['column' => 'person_id', 'count' => 850], ['column' => 'communication_id', 'count' => 5000]]],
            ['table_id' => 3, 'name' => 'b_1', 'collection_id' => 2, 'collection' => 'Beta', 'rows' => null, 'ids' => []],
        ];
        $o = (new DashboardPlanner)->overview($items);
        $this->assertSame(3, $o['tables']);
        $this->assertSame(2, $o['with_counts']);
        $this->assertSame(2, $o['collections']);
        $this->assertSame(6000, $o['records']);
        $this->assertSame(900, $o['people'], 'the largest person count, not a sum');
        $this->assertSame('1a_x', $o['people_from']);
        $this->assertSame('Alpha', $o['by_collection'][0]['name']);
        $this->assertSame(6000, $o['by_collection'][0]['records']);
        $this->assertSame(0, $o['by_collection'][1]['records']);
    }

    public function testOverviewOfNothing() {
        $o = (new DashboardPlanner)->overview([]);
        $this->assertSame(0, $o['tables']);
        $this->assertNull($o['people']);
        $this->assertSame([], $o['by_collection']);
    }

    public function testSummaryIsTheLightPartOfAPayload() {
        $s = DashboardPlanner::summaryOf(['rows' => 10, 'ids' => [['column' => 'a_id', 'count' => 3]], 'breakdowns' => [1, 2], 'trends' => [1], 'partial' => true, 'skipped' => ['x']]);
        $this->assertSame(['rows' => 10, 'ids' => [['column' => 'a_id', 'count' => 3]], 'breakdowns' => 2, 'trends' => 1, 'partial' => true], $s);
    }
}
