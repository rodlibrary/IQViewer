<?php
  # tests/Feature/Controllers/DashboardControllerTest.php

  use App\Jobs\RefreshDashboard;
  use App\Models\DashboardSnapshot;
  use App\Models\Table;
  use App\Models\User;
  use Illuminate\Support\Facades\Queue;

  /**
   * Routes, access rules and the stored counts of the data dashboard. The page
   * only reads dashboard_snapshots, so no data table needs to be filled; the
   * counting itself (DashboardService) is covered by tests/Unit/Charts.
   */
  class DashboardControllerTest extends BrowserKitTestCase {
     private $admin;
     private $user;
     private $tableName;
     private $table;

     public function setUp(): void {
          parent::setUp();

          $this->admin = User::where('name', '=', 'admin')->first();
          $this->user = User::where('name', '=', 'test')->first();

          $colName = $this->testHelper->generateCollectionName();
          $this->tableName = $this->testHelper->createTableName();
          $collection = $this->testHelper->createCollection($colName);
          $this->testHelper->createEmptyTestTable($this->tableName, $collection->id);
          $this->table = Table::where('tblNme', '=', $this->tableName)->first();

          config(['charts.admin_only' => false]);
     }

     protected function tearDown(): void {
          $this->testHelper->cleanupTestTables();
          $this->testHelper->deleteTestCollections();
          parent::tearDown();
     }

     private function snapshot($updatedAt = null) {
          $payload = ['version' => 1, 'table' => ['id' => $this->table->id, 'name' => $this->tableName], 'rows' => 120,
                      'ids' => [['column' => 'person_id', 'label' => 'Person ID', 'count' => 90]],
                      'breakdowns' => [], 'trends' => [], 'partial' => false, 'skipped' => [], 'min_cell_size' => 10, 'seconds' => 1];
          return DashboardSnapshot::create([
               'table_id' => $this->table->id,
               'table_updated_at' => $updatedAt ?: $this->table->updated_at,
               'row_count' => 120,
               'summary' => json_encode(['rows' => 120, 'ids' => $payload['ids'], 'breakdowns' => 0, 'trends' => 0, 'partial' => false]),
               'payload' => json_encode($payload),
               'seconds' => 1,
               'computed_at' => now(),
          ]);
     }

     // ---------------- access

     public function testGuestIsSentToLogin() {
          $this->get('dashboard')->assertResponseStatus(302);
          $this->get('dashboard/data')->assertResponseStatus(302);
     }

     public function testSignedInUserCanOpenThePage() {
          $this->actingAs($this->user)->get('dashboard')->assertResponseOk()->see('Data Dashboard');
     }

     public function testAdminOnlySettingKeepsOrdinaryUsersOut() {
          config(['charts.admin_only' => true]);
          $this->actingAs($this->user)->get('dashboard')->assertResponseStatus(302);
          $this->actingAs($this->admin)->get('dashboard')->assertResponseOk();
     }

     // ---------------- data

     public function testDataListsTablesWithTheirCounts() {
          $this->snapshot();
          $this->actingAs($this->user)
               ->get('dashboard/data')
               ->assertResponseOk()
               ->seeJsonStructure(['overview' => ['records', 'tables', 'collections'], 'tables', 'min_cell_size', 'can_refresh'])
               ->seeJson(['name' => $this->tableName, 'status' => 'ready', 'rows' => 120, 'can_refresh' => false]);
     }

     public function testATableChangedSinceCountingIsMarkedStale() {
          $this->snapshot('2001-01-01 00:00:00');
          $this->actingAs($this->user)->get('dashboard/data')->seeJson(['name' => $this->tableName, 'status' => 'stale']);
     }

     public function testATableNeverCountedIsMissing() {
          $this->actingAs($this->user)->get('dashboard/data')->seeJson(['name' => $this->tableName, 'status' => 'missing', 'rows' => null]);
     }

     public function testDisabledTableAndCollectionAreHidden() {
          $this->snapshot();
          \DB::table('tables')->where('id', $this->table->id)->update(['hasAccess' => 0]);
          $this->actingAs($this->user)->get('dashboard/data')->dontSee($this->tableName);
          $this->actingAs($this->user)->get('dashboard/table/' . $this->table->id)->assertResponseStatus(403);

          \DB::table('tables')->where('id', $this->table->id)->update(['hasAccess' => 1]);
          \DB::table('collections')->where('id', $this->table->collection_id)->update(['isEnabled' => 0]);
          $this->actingAs($this->user)->get('dashboard/data')->dontSee($this->tableName);
          $this->actingAs($this->user)->get('dashboard/table/' . $this->table->id)->assertResponseStatus(403);
     }

     public function testDetailReturnsThePayloadOr404() {
          $this->actingAs($this->user)->get('dashboard/table/' . $this->table->id)->assertResponseStatus(404);
          $this->snapshot();
          $this->actingAs($this->user)
               ->get('dashboard/table/' . $this->table->id)
               ->assertResponseOk()
               ->seeJsonStructure(['table', 'status', 'payload' => ['rows', 'ids', 'breakdowns', 'trends']]);
     }

     // ---------------- refresh

     public function testOnlyAdministratorsCanRefresh() {
          Queue::fake();
          $this->actingAs($this->user)->json('POST', 'dashboard/refresh', ['table_id' => $this->table->id])->assertResponseStatus(403);
          Queue::assertNotPushed(RefreshDashboard::class);

          $this->actingAs($this->admin)->json('POST', 'dashboard/refresh', ['table_id' => $this->table->id])
               ->assertResponseOk()->seeJson(['queued' => 1]);
          Queue::assertPushed(RefreshDashboard::class);
     }

     public function testRefreshAllQueuesEveryVisibleTable() {
          Queue::fake();
          $this->actingAs($this->admin)->json('POST', 'dashboard/refresh', ['all' => 1])->assertResponseOk();
          Queue::assertPushed(RefreshDashboard::class);
     }
  }
