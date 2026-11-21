<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DataCsvIngestionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(Company $c,string $suffix): void
    {
        $role=Role::where('name','admin')->value('id');
        $user=User::create(['name'=>'Data','email'=>'data-csv-'.$suffix.'@example.test',
            'password'=>'TestPassword2026!','role_id'=>$role]);
        $user->companies()->attach($c->id,['role_id'=>$role]);
        $this->actingAs($user)->withSession(['active_company_id'=>$c->id]);
    }

    private function batch(string $csv, int $expected): int
    {
        $source=$this->postJson('/api/data/sources',[
            'code'=>'CSV-INGEST','name'=>'Fictive source',
            'source_type'=>'csv','classification'=>'internal',
        ])->assertCreated()->json('id');
        $this->postJson("/api/data/sources/$source/validate")->assertOk();
        return $this->postJson('/api/data/ingestion-batches',[
            'source_id'=>$source,'batch_key'=>'v1',
            'sha256'=>hash('sha256',$csv),'expected_rows'=>$expected,
        ])->assertCreated()->json('id');
    }

    public function test_csv_ingests_two_rows_once_and_rejects_foreign_company(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $b=Company::create(['code'=>'CSV_B','name'=>'Synthetic B']);
        $this->admin($a,'a');
        $csv="external_id,amount_cents\nsale-1,1200\nsale-2,-99\n";
        $id=$this->batch($csv,2);
        $this->post('/api/data/ingestion-batches/'.$id.'/ingest-csv',[
            'file'=>UploadedFile::fake()->createWithContent('batch.csv',$csv),
        ])->assertOk()->assertJsonPath('status','processed');
        $this->assertDatabaseCount('data_ingested_csv_rows',2);
        $this->post('/api/data/ingestion-batches/'.$id.'/ingest-csv',[
            'file'=>UploadedFile::fake()->createWithContent('batch.csv',$csv),
        ])->assertOk();
        $this->assertDatabaseCount('data_ingested_csv_rows',2);
        $this->admin($b,'b');
        $this->post('/api/data/ingestion-batches/'.$id.'/ingest-csv',[
            'file'=>UploadedFile::fake()->createWithContent('batch.csv',$csv),
        ])->assertNotFound();
    }

    public function test_invalid_row_fails_without_partial_ingestion(): void
    {
        $a=Company::where('code','ATLAS_DEMO')->firstOrFail();
        $this->admin($a,'bad');
        $csv="external_id,amount_cents\nsale-1,100\nsale-1,200\n";
        $id=$this->batch($csv,2);
        $this->post('/api/data/ingestion-batches/'.$id.'/ingest-csv',[
            'file'=>UploadedFile::fake()->createWithContent('batch.csv',$csv),
        ])->assertStatus(422);
        $this->assertDatabaseCount('data_ingested_csv_rows',0);
        $this->assertDatabaseHas('data_ingestion_batches',['id'=>$id,'status'=>'registered']);
    }
}
