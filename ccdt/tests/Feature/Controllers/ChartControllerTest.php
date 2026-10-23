<?php
  # tests/Feature/Controllers/ChartControllerTest.php

  use App\Models\User;
  use App\Models\Table;
  use App\Models\SavedChart;
  use App\Libraries\Charts\ChartService;
  use App\Libraries\Charts\ChartSpecException;

  /**
   * Routes, access rules and saved charts of the chart builder.
   *
   * The chart service (database aggregation + matplotlib service) is replaced
   * by a mock, so these tests need no charts container. The query builder
   * itself is covered by tests/Unit/Charts.
   */
  class ChartControllerTest extends BrowserKitTestCase {
     private $admin;
     private $user;
     private $other;
     private $colName;
     private $tableName;
     private $table;

     public function setUp(): void {
          parent::setUp();

          $this->admin = User::where('name', '=', 'admin')->first();
          $this->user = User::where('name', '=', 'test')->first();
          // a second, ordinary user (no model factory needed)
          $this->other = new User();
          $this->other->name = 'chart_other_user';
          $this->other->email = 'chart_other_user@example.com';
          $this->other->password = bcrypt('secret');
          $this->other->isAdmin = false;
          $this->other->save();

          // a collection with one enabled table
          $this->colName = $this->testHelper->generateCollectionName();
          $this->tableName = $this->testHelper->createTableName();
          $collection = $this->testHelper->createCollection($this->colName);
          $this->testHelper->createEmptyTestTable($this->tableName, $collection->id);
          $this->table = Table::where('tblNme', '=', $this->tableName)->first();

          config(['charts.admin_only' => false]);
     }

     protected function tearDown(): void {
          $this->testHelper->cleanupTestTables();
          $this->testHelper->deleteTestCollections();
          parent::tearDown();
     }

     /** Replace the chart service with a mock that answers describe/builder calls. */
     private function mockService() {
          $mock = Mockery::mock(ChartService::class);
          $this->app->instance(ChartService::class, $mock);
          return $mock;
     }

     private function spec() {
          return ['chart' => 'bar', 'x' => ['column' => 'status'], 'measure' => ['fn' => 'count']];
     }

     // ---------------- access

     public function testGuestIsSentToLogin() {
          $this->get('charts')->assertResponseStatus(302);
          $this->get('charts/table/' . $this->table->id)->assertResponseStatus(302);
     }

     public function testSignedInUserCanOpenTheBuilder() {
          $this->actingAs($this->user)
               ->get('charts')
               ->assertResponseOk()
               ->see('Chart Builder')
               ->see($this->tableName);
     }

     public function testAdminOnlySettingKeepsOrdinaryUsersOut() {
          config(['charts.admin_only' => true]);
          $this->actingAs($this->user)->get('charts')->assertResponseStatus(302);
          $this->actingAs($this->admin)->get('charts')->assertResponseOk();
     }

     public function testOldAdminUrlIsGone() {
          $this->actingAs($this->admin)->get('admin/charts')->assertResponseStatus(404);
     }

     public function testDisabledTableIsNotListedOrChartable() {
          \DB::table('tables')->where('id', $this->table->id)->update(['hasAccess' => 0]);

          $this->actingAs($this->user)->get('charts')->dontSee($this->tableName);
          $this->actingAs($this->user)->get('charts/table/' . $this->table->id)->assertResponseStatus(403);
          $this->actingAs($this->user)
               ->json('POST', 'charts/render', ['table_id' => $this->table->id, 'spec' => $this->spec()])
               ->assertResponseStatus(403);
     }

     public function testTableOfDisabledCollectionIsNotChartable() {
          \DB::table('collections')->where('id', $this->table->collection_id)->update(['isEnabled' => 0]);

          $this->actingAs($this->user)->get('charts/table/' . $this->table->id)->assertResponseStatus(403);
     }

     // ---------------- describe / render

     public function testDescribeReturnsColumnsStartersAndJoinableTables() {
          $this->actingAs($this->user)
               ->get('charts/table/' . $this->table->id)
               ->assertResponseOk()
               ->seeJsonStructure(['columns', 'starters', 'joinable']);
     }

     public function testDescribeJoinRefusesATableOfAnotherCollection() {
          $otherCollection = $this->testHelper->createCollection($this->testHelper->generateCollectionName());
          $otherTable = $this->testHelper->createTableName();
          $this->testHelper->createEmptyTestTable($otherTable, $otherCollection->id);
          $other = Table::where('tblNme', '=', $otherTable)->first();

          $this->actingAs($this->user)
               ->get('charts/table/' . $this->table->id . '/with/' . $other->id)
               ->assertResponseStatus(422)
               ->seeJson(['error' => 'That table cannot be combined with this one.']);
     }

     public function testRenderReturnsTheImageAndTheNumbers() {
          $shaped = ['chart' => 'bar', 'x_kind' => 'category', 'bucket' => null, 'title' => 'By status', 'x_label' => 'Status',
                     'y_label' => 'Records', 'x' => ['C', 'O'], 'series' => [['name' => 'Records', 'values' => [40, 12]]], 'suppressed' => 0];
          $this->mockService()->shouldReceive('run')->once()->andReturn([
               'shaped' => $shaped, 'png' => base64_encode('png'), 'query_ms' => 5, 'render_ms' => 7, 'cached' => false, 'summary' => true,
          ]);

          $this->actingAs($this->user)
               ->json('POST', 'charts/render', ['table_id' => $this->table->id, 'spec' => $this->spec()])
               ->assertResponseOk()
               ->seeJson(['chart' => 'bar', 'x' => ['C', 'O'], 'summary' => true, 'image' => 'data:image/png;base64,' . base64_encode('png')]);
     }

     public function testBadSpecIsReportedNotThrown() {
          $this->mockService()->shouldReceive('run')->once()->andThrow(new ChartSpecException('Unknown column: nope'));

          $this->actingAs($this->user)
               ->json('POST', 'charts/render', ['table_id' => $this->table->id, 'spec' => $this->spec()])
               ->assertResponseStatus(422)
               ->seeJson(['error' => 'Unknown column: nope']);
     }

     public function testRenderServiceDownIs503() {
          $this->mockService()->shouldReceive('run')->once()->andThrow(new \RuntimeException('connection refused'));

          $this->actingAs($this->user)
               ->json('POST', 'charts/render', ['table_id' => $this->table->id, 'spec' => $this->spec()])
               ->assertResponseStatus(503);
     }

     public function testRenderNeedsASpec() {
          $this->actingAs($this->user)
               ->json('POST', 'charts/render', ['table_id' => $this->table->id])
               ->assertResponseStatus(422);
     }

     // ---------------- saved charts

     private function saveAs($user, $name) {
          $builder = Mockery::mock();
          $builder->shouldReceive('normalize')->andReturn($this->spec());
          $this->mockService()->shouldReceive('builder')->andReturn($builder);
          $this->actingAs($user)
               ->json('POST', 'charts/save', ['table_id' => $this->table->id, 'name' => $name, 'spec' => $this->spec()])
               ->assertResponseOk();
          return SavedChart::where('name', '=', $name)->first();
     }

     public function testSavedChartBelongsToItsOwner() {
          $chart = $this->saveAs($this->user, 'My chart');
          $this->assertNotNull($chart);
          $this->assertEquals($this->user->id, $chart->user_id);

          // the owner sees it on the page, another user does not, an administrator does
          $this->actingAs($this->user)->get('charts')->see('My chart');
          $this->actingAs($this->other)->get('charts')->dontSee('My chart');
          $this->actingAs($this->admin)->get('charts')->see('My chart');
     }

     public function testSavedChartNeedsAName() {
          $this->actingAs($this->user)
               ->json('POST', 'charts/save', ['table_id' => $this->table->id, 'name' => '  ', 'spec' => $this->spec()])
               ->assertResponseStatus(422);
     }

     public function testOnlyTheOwnerOrAnAdminMayDeleteASavedChart() {
          $chart = $this->saveAs($this->user, 'To delete');

          $this->actingAs($this->other)
               ->json('POST', 'charts/delete', ['id' => $chart->id])
               ->assertResponseStatus(404);
          $this->assertNotNull(SavedChart::find($chart->id));

          $this->actingAs($this->user)
               ->json('POST', 'charts/delete', ['id' => $chart->id])
               ->assertResponseOk();
          $this->assertNull(SavedChart::find($chart->id));
     }

     public function testAnAdminMayDeleteAnyone() {
          $chart = $this->saveAs($this->user, 'Admin deletes');

          $this->actingAs($this->admin)
               ->json('POST', 'charts/delete', ['id' => $chart->id])
               ->assertResponseOk();
          $this->assertNull(SavedChart::find($chart->id));
     }
  }
