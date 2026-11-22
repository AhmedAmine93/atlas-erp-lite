<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Oracle JET industrial domain packages for PostgreSQL 17.
 * PostgreSQL has no Oracle PACKAGE construct; schema-qualified PL/pgSQL
 * functions implement atomic invariants, called only through authorized Laravel.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            // The industrial GMAO domain is PostgreSQL-only.
            return;
        }
        DB::statement('CREATE SCHEMA IF NOT EXISTS atlas_industrial');
        Schema::create('industrial_meter_readings', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('asset_id');
            $t->string('meter_code',32);
            $t->decimal('reading_value',16,3);
            $t->string('unit',16);
            $t->string('idempotency_key',64);
            $t->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('recorded_at')->useCurrent();
            $t->foreign(['company_id','asset_id'])->references(['company_id','id'])
                ->on('maintenance_assets')->restrictOnDelete();
            $t->unique(['company_id','asset_id','meter_code','idempotency_key'],'industrial_meter_idempotency_uq');
            $t->unique(['company_id','id']);
            $t->index(['company_id','asset_id','meter_code','id'],'industrial_meter_latest_idx');
        });
        Schema::create('industrial_inspection_templates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->string('code',40);
            $t->string('title',160);
            $t->string('description',500)->nullable();
            $t->boolean('active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id','code']);
            $t->unique(['company_id','id']);
        });
        Schema::create('industrial_inspection_runs', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $t->unsignedBigInteger('asset_id');
            $t->unsignedBigInteger('template_id');
            $t->string('result',16);
            $t->text('observations');
            $t->string('idempotency_key',64);
            $t->foreignId('inspected_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('inspected_at')->useCurrent();
            $t->foreign(['company_id','asset_id'])->references(['company_id','id'])->on('maintenance_assets')->restrictOnDelete();
            $t->foreign(['company_id','template_id'])->references(['company_id','id'])->on('industrial_inspection_templates')->restrictOnDelete();
            $t->unique(['company_id','id']);
            $t->unique(['company_id','asset_id','template_id','idempotency_key'],'industrial_inspection_replay_uq');
            $t->index(['company_id','asset_id','inspected_at']);
        });

        DB::statement("ALTER TABLE industrial_meter_readings ADD CONSTRAINT industrial_meter_positive CHECK (reading_value >= 0 AND reading_value <= 999999999999.999)");
        DB::statement("ALTER TABLE industrial_meter_readings ADD CONSTRAINT industrial_meter_code CHECK (meter_code IN ('HOURS','CYCLES','DISTANCE','ENERGY'))");
        DB::statement("ALTER TABLE industrial_meter_readings ADD CONSTRAINT industrial_meter_unit CHECK ((meter_code='HOURS' AND unit='h') OR (meter_code='CYCLES' AND unit='cycles') OR (meter_code='DISTANCE' AND unit='km') OR (meter_code='ENERGY' AND unit='kWh'))");
        DB::statement("ALTER TABLE industrial_inspection_runs ADD CONSTRAINT industrial_inspection_result_check CHECK (result IN ('pass','fail','observation'))");
        DB::statement("ALTER TABLE industrial_inspection_runs ADD CONSTRAINT industrial_inspection_observation_check CHECK (char_length(trim(observations)) BETWEEN 10 AND 2000)");
        $tables=['industrial_meter_readings','industrial_inspection_templates','industrial_inspection_runs'];
        foreach ($tables as $table) {
            DB::statement("GRANT SELECT, INSERT ON public.\"{$table}\" TO atlas_app_runtime");
            DB::statement("REVOKE UPDATE, DELETE ON public.\"{$table}\" FROM atlas_app_runtime");
            DB::statement("GRANT USAGE, SELECT ON SEQUENCE public.{$table}_id_seq TO atlas_app_runtime");
            DB::statement("ALTER TABLE public.\"{$table}\" ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY industrial_runtime_tenant ON public.\"{$table}\" FOR ALL TO atlas_app_runtime USING (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint) WITH CHECK (company_id = NULLIF(current_setting('atlas.tenant_id', true), '')::bigint)");
        }
        DB::statement('GRANT USAGE ON SCHEMA atlas_industrial TO atlas_app_runtime');

        // SECURITY INVOKER: runtime role NEVER gains owner privileges or bypasses RLS.
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION atlas_industrial.record_meter(
 p_company bigint,p_asset bigint,p_code text,p_value numeric,p_key text,p_actor bigint
) RETURNS TABLE(reading_id bigint,replayed boolean)
LANGUAGE plpgsql VOLATILE SECURITY INVOKER
SET search_path=pg_catalog,public,atlas_industrial
AS $pkg$
DECLARE prior record; asset_status text; unit_name text; created_id bigint;
BEGIN
 IF p_company IS NULL OR p_company < 1 OR p_asset IS NULL OR p_asset < 1
    OR p_actor IS NULL OR p_actor < 1 OR p_value IS NULL
    OR p_value < 0 OR p_value > 999999999999.999
    OR p_key !~ '^[A-Za-z0-9_-]{8,64}$' THEN
   RAISE EXCEPTION 'Invalid industrial meter request' USING ERRCODE='22023';
 END IF;
 IF p_company IS DISTINCT FROM NULLIF(current_setting('atlas.tenant_id',true),'')::bigint THEN
   RAISE EXCEPTION 'Tenant mismatch' USING ERRCODE='42501';
 END IF;
 unit_name := CASE p_code WHEN 'HOURS' THEN 'h' WHEN 'CYCLES' THEN 'cycles'
                       WHEN 'DISTANCE' THEN 'km' WHEN 'ENERGY' THEN 'kWh' ELSE NULL END;
 IF unit_name IS NULL THEN RAISE EXCEPTION 'Unknown meter' USING ERRCODE='22023'; END IF;
 -- Serialize all readings per asset, including distinct meter types, using its row lock.
 SELECT status INTO asset_status FROM public.maintenance_assets
  WHERE company_id=p_company AND id=p_asset FOR UPDATE;
 IF asset_status IS NULL THEN RAISE EXCEPTION 'Asset not found' USING ERRCODE='P0002'; END IF;
 IF asset_status='retired' THEN RAISE EXCEPTION 'Retired asset cannot be read' USING ERRCODE='23514'; END IF;
 SELECT id,reading_value INTO prior
  FROM public.industrial_meter_readings
  WHERE company_id=p_company AND asset_id=p_asset AND meter_code=p_code AND idempotency_key=p_key;
 IF FOUND THEN
   IF prior.reading_value IS DISTINCT FROM p_value THEN
    RAISE EXCEPTION 'Idempotency key reused with different payload' USING ERRCODE='23505';
   END IF;
   reading_id:=prior.id; replayed:=true; RETURN NEXT; RETURN;
 END IF;
 SELECT reading_value INTO prior FROM public.industrial_meter_readings
  WHERE company_id=p_company AND asset_id=p_asset AND meter_code=p_code
  ORDER BY id DESC LIMIT 1;
 IF FOUND AND p_value < prior.reading_value THEN
   RAISE EXCEPTION 'Meter reading cannot decrease' USING ERRCODE='23514';
 END IF;
 INSERT INTO public.industrial_meter_readings
  (company_id,asset_id,meter_code,reading_value,unit,idempotency_key,recorded_by)
 VALUES (p_company,p_asset,p_code,p_value,unit_name,p_key,p_actor)
 RETURNING id INTO created_id;
 reading_id:=created_id; replayed:=false; RETURN NEXT;
END
$pkg$
SQL);

        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION atlas_industrial.record_inspection(
 p_company bigint,p_asset bigint,p_template bigint,p_result text,
 p_observations text,p_key text,p_actor bigint
) RETURNS TABLE(inspection_id bigint,replayed boolean)
LANGUAGE plpgsql VOLATILE SECURITY INVOKER
SET search_path=pg_catalog,public,atlas_industrial
AS $pkg$
DECLARE existing record; created_id bigint;
BEGIN
 IF p_company IS NULL OR p_company < 1 OR p_asset IS NULL OR p_asset < 1
    OR p_template IS NULL OR p_template < 1 OR p_actor IS NULL OR p_actor < 1
    OR p_result NOT IN ('pass','fail','observation')
    OR p_key !~ '^[A-Za-z0-9_-]{8,64}$'
    OR p_observations IS NULL OR char_length(trim(p_observations)) NOT BETWEEN 10 AND 2000
 THEN RAISE EXCEPTION 'Invalid inspection' USING ERRCODE='22023'; END IF;
 IF p_company IS DISTINCT FROM NULLIF(current_setting('atlas.tenant_id',true),'')::bigint THEN
    RAISE EXCEPTION 'Tenant mismatch' USING ERRCODE='42501';
 END IF;
 PERFORM 1 FROM public.maintenance_assets WHERE company_id=p_company AND id=p_asset FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'Asset not found' USING ERRCODE='P0002'; END IF;
 PERFORM 1 FROM public.industrial_inspection_templates
  WHERE company_id=p_company AND id=p_template AND active=true;
 IF NOT FOUND THEN RAISE EXCEPTION 'Active template not found' USING ERRCODE='P0002'; END IF;
 SELECT id,result,observations INTO existing
 FROM public.industrial_inspection_runs
 WHERE company_id=p_company AND asset_id=p_asset AND template_id=p_template AND idempotency_key=p_key;
 IF FOUND THEN
   IF existing.result IS DISTINCT FROM p_result OR existing.observations IS DISTINCT FROM p_observations THEN
     RAISE EXCEPTION 'Idempotency key reused with different inspection' USING ERRCODE='23505';
   END IF;
   inspection_id:=existing.id;replayed:=true;RETURN NEXT;RETURN;
 END IF;
 INSERT INTO public.industrial_inspection_runs
  (company_id,asset_id,template_id,result,observations,idempotency_key,inspected_by)
 VALUES (p_company,p_asset,p_template,p_result,p_observations,p_key,p_actor)
 RETURNING id INTO created_id;
 inspection_id:=created_id;replayed:=false;RETURN NEXT;
END
$pkg$
SQL);
        foreach (['record_meter(bigint,bigint,text,numeric,text,bigint)',
                  'record_inspection(bigint,bigint,bigint,text,text,text,bigint)'] as $signature) {
            DB::statement("REVOKE ALL ON FUNCTION atlas_industrial.{$signature} FROM PUBLIC");
            DB::statement("GRANT EXECUTE ON FUNCTION atlas_industrial.{$signature} TO atlas_app_runtime");
        }
    }

    public function down(): void
    {
        foreach (['industrial_inspection_runs','industrial_meter_readings','industrial_inspection_templates'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Refusing destructive industrial journal rollback.');
            }
        }
        if (DB::getDriverName()==='pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS atlas_industrial.record_inspection(bigint,bigint,bigint,text,text,text,bigint)');
            DB::statement('DROP FUNCTION IF EXISTS atlas_industrial.record_meter(bigint,bigint,text,numeric,text,bigint)');
        }
        Schema::dropIfExists('industrial_inspection_runs');
        Schema::dropIfExists('industrial_meter_readings');
        Schema::dropIfExists('industrial_inspection_templates');
        if (DB::getDriverName()==='pgsql') DB::statement('DROP SCHEMA IF EXISTS atlas_industrial');
    }
};
