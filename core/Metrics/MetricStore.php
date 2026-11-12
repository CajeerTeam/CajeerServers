<?php
declare(strict_types=1);
namespace Cajeer\Servers\Metrics;

use Cajeer\Servers\Http\Exception\HttpException;
use PDO;

/** SQL time series. Ingestion is called inside the fenced connector commit transaction. */
final class MetricStore
{
    private const SERIES_LIMIT=10000;
    private const RETENTION_DAYS=30;
    private static function q(PDO $pdo,string $sql,array $args=[]):\PDOStatement {$q=$pdo->prepare($sql);$q->execute($args);return $q;}
    private static function uuid(mixed $v):string{return \Cajeer\Servers\Credentials\CredentialInput::uuid($v);}
    private static function time(mixed $v):\DateTimeImmutable
    {
        if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?Z$/D',$v))throw new HttpException(422,'Expected UTC timestamp.','validation_failed');
        try{$t=new \DateTimeImmutable($v);$err=\DateTimeImmutable::getLastErrors();}catch(\Exception){throw new HttpException(422,'Invalid timestamp.','validation_failed');}
        if($err!==false)throw new HttpException(422,'Invalid timestamp.','validation_failed');return $t;
    }
    public static function ingest(PDO $pdo,array $target,array $samples,string $observedAt):array
    {
        if(!$pdo->inTransaction())throw new \LogicException('Metric ingestion requires the fenced transaction.');
        $samples=MetricBatch::normalize($samples,$observedAt);
        if($samples===[])throw new HttpException(422,'Empty metrics batch.','metric_invalid');
        $ws=self::uuid($target['workspace_id']);$id=self::uuid($target['id']);$asset=self::uuid($target['asset_id']);$version=(int)$target['version'];
        // Workspace lock is held by ConnectorRuntimeService. Enforce it here too for other trusted callers.
        self::q($pdo,'SELECT id FROM workspaces WHERE id=CAST(:ws AS uuid) FOR UPDATE',['ws'=>$ws]);
        $series=[];$batch=[];$months=[];
        foreach($samples as $r){$key=hash('sha256',json_encode([$r['metric'],$r['resource_external_id'],$r['labels']],JSON_THROW_ON_ERROR));
            $definition=['key'=>$key,'metric'=>$r['metric'],'resource'=>$r['resource_external_id'],'labels'=>(object)$r['labels'],'kind'=>$r['kind'],'unit'=>$r['unit']];
            if(isset($series[$key])&&$series[$key]!==$definition){ // compare objects by content, not identity
                if($series[$key]!=$definition)throw new HttpException(422,'Inconsistent metric series metadata.','metric_invalid');
            }
            $series[$key]=$definition;$batch[]=['key'=>$key,'at'=>$r['observed_at'],'value'=>(string)$r['value']];$months[substr($r['observed_at'],0,7)]=true;
        }
        $scope=['ws'=>$ws,'target'=>$id,'asset'=>$asset,'version'=>$version];
        $defs=json_encode(array_values($series),JSON_THROW_ON_ERROR);$data=json_encode($batch,JSON_THROW_ON_ERROR);
        $missing=(int)self::q($pdo,"SELECT count(*) FROM jsonb_to_recordset(CAST(:defs AS jsonb)) AS d(key text) WHERE NOT EXISTS(SELECT 1 FROM metric_series s WHERE s.workspace_id=CAST(:ws AS uuid) AND s.target_id=CAST(:target AS uuid) AND s.asset_id=CAST(:asset AS uuid) AND s.target_version=:version AND s.identity=d.key)",['defs'=>$defs,...$scope])->fetchColumn();
        $count=(int)self::q($pdo,'SELECT count(*) FROM metric_series WHERE workspace_id=CAST(:ws AS uuid)',['ws'=>$ws])->fetchColumn();
        if($count+$missing>self::SERIES_LIMIT)throw new HttpException(422,'Workspace metric series quota exceeded.','metric_quota');
        foreach(array_keys($months) as $month)self::q($pdo,'SELECT cajeer_metric_partition(CAST(:month AS date))',['month'=>$month.'-01']);
        self::q($pdo,"INSERT INTO metric_series(workspace_id,target_id,asset_id,target_version,identity,metric,resource_external_id,labels,kind,unit)
            SELECT CAST(:ws AS uuid),CAST(:target AS uuid),CAST(:asset AS uuid),:version,d.key,d.metric,d.resource,d.labels,d.kind,d.unit FROM jsonb_to_recordset(CAST(:defs AS jsonb)) AS d(key text,metric text,resource text,labels jsonb,kind text,unit text)
            ON CONFLICT(workspace_id,target_id,asset_id,target_version,identity) DO NOTHING",[...$scope,'defs'=>$defs]);
        $bad=(int)self::q($pdo,"SELECT count(*) FROM jsonb_to_recordset(CAST(:defs AS jsonb)) AS d(key text,metric text,resource text,labels jsonb,kind text,unit text) JOIN metric_series s ON s.identity=d.key AND s.workspace_id=CAST(:ws AS uuid) AND s.target_id=CAST(:target AS uuid) AND s.asset_id=CAST(:asset AS uuid) AND s.target_version=:version WHERE s.kind<>d.kind OR s.unit IS DISTINCT FROM d.unit OR s.metric<>d.metric OR s.resource_external_id<>d.resource OR s.labels<>d.labels",[...$scope,'defs'=>$defs])->fetchColumn();
        if($bad)throw new HttpException(409,'Metric series metadata changed.','metric_conflict');
        $join=' JOIN metric_series s ON s.identity=d.key AND s.workspace_id=CAST(:ws AS uuid) AND s.target_id=CAST(:target AS uuid) AND s.asset_id=CAST(:asset AS uuid) AND s.target_version=:version';
        $insert=self::q($pdo,"INSERT INTO metric_samples(series_id,observed_at,value) SELECT s.id,d.at,d.value FROM jsonb_to_recordset(CAST(:data AS jsonb)) AS d(key text,at timestamptz,value numeric) $join ON CONFLICT(series_id,observed_at) DO NOTHING",[...$scope,'data'=>$data])->rowCount();
        $bad=(int)self::q($pdo,"SELECT count(*) FROM jsonb_to_recordset(CAST(:data AS jsonb)) AS d(key text,at timestamptz,value numeric) $join JOIN metric_samples m ON m.series_id=s.id AND m.observed_at=d.at WHERE m.value<>d.value",[...$scope,'data'=>$data])->fetchColumn();
        if($bad)throw new HttpException(409,'Conflicting sample at identical timestamp.','metric_conflict');
        self::q($pdo,"UPDATE metric_series s SET last_seen_at=clock_timestamp() FROM jsonb_to_recordset(CAST(:defs AS jsonb)) AS d(key text) WHERE s.identity=d.key AND s.workspace_id=CAST(:ws AS uuid) AND s.target_id=CAST(:target AS uuid) AND s.asset_id=CAST(:asset AS uuid) AND s.target_version=:version",[...$scope,'defs'=>$defs]);
        return ['samples'=>count($batch),'inserted'=>$insert,'deduplicated'=>count($batch)-$insert,'series'=>count($series)];
    }
    public static function series(PDO $pdo,array $target,int $limit=100,int $offset=0,?string $metric=null):array
    {
        if($limit<1||$limit>100||$offset<0||$offset>10000||($metric!==null&&!preg_match('/^[A-Za-z_:][A-Za-z0-9_.:-]{0,127}$/D',$metric)))throw new HttpException(422,'Invalid series filter.','validation_failed');
        $args=['ws'=>$target['workspace_id'],'target'=>$target['id']];$filter='';if($metric!==null){$filter=' AND metric=:metric';$args['metric']=$metric;}
        $q=$pdo->prepare('SELECT id::text,asset_id::text,target_version,metric,resource_external_id,labels::text,kind,unit,last_seen_at::text FROM metric_series WHERE workspace_id=CAST(:ws AS uuid) AND target_id=CAST(:target AS uuid)'.$filter.' ORDER BY metric,id LIMIT :lim OFFSET :off');
        foreach($args as $k=>$v)$q->bindValue(':'.$k,$v);$q->bindValue(':lim',$limit,PDO::PARAM_INT);$q->bindValue(':off',$offset,PDO::PARAM_INT);$q->execute();$rows=$q->fetchAll(PDO::FETCH_ASSOC);foreach($rows as &$row){$row['labels']=json_decode($row['labels'],false,16,JSON_THROW_ON_ERROR);$row['target_version']=(int)$row['target_version'];$row['last_seen_at']=(new \DateTimeImmutable($row['last_seen_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');}unset($row);return $rows;
    }
    public static function query(PDO $pdo,array $target,string $series,string $from,string $to,string $aggregation='raw',int $step=60):array
    {
        $a=self::time($from);$b=self::time($to);$seconds=$b->getTimestamp()-$a->getTimestamp();
        if($seconds<1||$seconds>self::RETENTION_DAYS*86400||!in_array($aggregation,['raw','avg','min','max','count','rate'],true)||$step<1||$step>86400||($aggregation!=='raw'&&ceil($seconds/$step)>1999))throw new HttpException(422,'Invalid metric range or step.','validation_failed');
        $id=self::uuid($series);$meta=self::q($pdo,'SELECT id::text,kind,metric,unit FROM metric_series WHERE id=CAST(:id AS uuid) AND workspace_id=CAST(:ws AS uuid) AND target_id=CAST(:target AS uuid)',['id'=>$id,'ws'=>$target['workspace_id'],'target'=>$target['id']])->fetch(PDO::FETCH_ASSOC);
        if(!$meta)throw new HttpException(404,'Metric series not found.','not_found');
        if($aggregation==='rate'&&$meta['kind']!=='counter')throw new HttpException(422,'Rate requires a counter.','validation_failed');
        $args=['id'=>$id,'from'=>$from,'to'=>$to];
        // Bound query cost independently of requested output size. Read at most 200,001 input points.
        $base="SELECT observed_at,value FROM metric_samples WHERE series_id=CAST(:id AS uuid) AND observed_at>=CAST(:from AS timestamptz) AND observed_at<CAST(:to AS timestamptz) AND observed_at>=clock_timestamp()-interval '30 days' ORDER BY observed_at LIMIT 200001";
        if($aggregation==='raw'){$rows=self::q($pdo,str_replace('LIMIT 200001','LIMIT 2001',$base),$args)->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>2000)throw new HttpException(422,'Too many raw points; use aggregation.','metric_query_limit');foreach($rows as &$r){$r=['timestamp'=>(new \DateTimeImmutable($r['observed_at']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),'value'=>(string)$r['value']];}unset($r);return ['series'=>$meta,'aggregation'=>$aggregation,'points'=>$rows];}
        $bucket="date_bin(make_interval(secs=>CAST(:step AS double precision)),observed_at,TIMESTAMPTZ '1970-01-01 00:00:00+00')";$args['step']=$step;
        if($aggregation==='rate'){$sql="WITH samples AS MATERIALIZED ($base), deltas AS (SELECT *,lag(value) OVER(ORDER BY observed_at) AS previous,lag(observed_at) OVER(ORDER BY observed_at) AS previous_at FROM samples) SELECT $bucket AS timestamp,(SELECT count(*) FROM samples) AS input_count,(sum(CASE WHEN value>=previous THEN value-previous ELSE value END)/NULLIF(sum(extract(epoch FROM observed_at-previous_at)),0))::text AS value FROM deltas WHERE previous IS NOT NULL GROUP BY 1 ORDER BY 1 LIMIT 2001";}
        else{$sql="WITH samples AS MATERIALIZED ($base) SELECT $bucket AS timestamp,(SELECT count(*) FROM samples) AS input_count,$aggregation(value)::text AS value FROM samples GROUP BY 1 ORDER BY 1 LIMIT 2001";}
        $rows=self::q($pdo,$sql,$args)->fetchAll(PDO::FETCH_ASSOC);if(count($rows)>2000)throw new HttpException(422,'Too many points.','metric_query_limit');foreach($rows as &$r){if((int)$r['input_count']>200000)throw new HttpException(422,'Metric query input limit exceeded.','metric_query_limit');unset($r['input_count']);$r['timestamp']=(new \DateTimeImmutable($r['timestamp']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');}unset($r);return ['series'=>$meta,'aggregation'=>$aggregation,'points'=>$rows];
    }
    /** Operator maintenance: bounded deletion plus empty-series cleanup. No network access. */
    public static function retain(PDO $pdo,int $limit=10000):array
    {
        if(!$pdo->inTransaction())throw new \LogicException('Retention requires transaction.');
        if($limit<1||$limit>100000)throw new \InvalidArgumentException('Retention batch limit.');
        $q=self::q($pdo,"WITH expired AS (SELECT series_id,observed_at FROM metric_samples WHERE observed_at<clock_timestamp()-interval '30 days' ORDER BY observed_at LIMIT $limit FOR UPDATE SKIP LOCKED) DELETE FROM metric_samples m USING expired e WHERE m.series_id=e.series_id AND m.observed_at=e.observed_at");
        $deleted=$q->rowCount();
        // Acquire the same workspace lock as ingestion before reclaiming unused series.
        $workspaces=$pdo->query("SELECT w.id FROM workspaces w WHERE EXISTS(SELECT 1 FROM metric_series s WHERE s.workspace_id=w.id AND s.last_seen_at<clock_timestamp()-interval '30 days' AND NOT EXISTS(SELECT 1 FROM metric_samples m WHERE m.series_id=s.id)) ORDER BY w.id LIMIT 10 FOR UPDATE SKIP LOCKED")->fetchAll(PDO::FETCH_COLUMN);
        $removed=0;foreach($workspaces as $ws)$removed+=self::q($pdo,"DELETE FROM metric_series WHERE id IN (SELECT s.id FROM metric_series s WHERE s.workspace_id=CAST(:ws AS uuid) AND s.last_seen_at<clock_timestamp()-interval '30 days' AND NOT EXISTS(SELECT 1 FROM metric_samples m WHERE m.series_id=s.id) LIMIT 1000)",['ws'=>$ws])->rowCount();
        return ['deleted'=>$deleted,'series_deleted'=>$removed,'retention_days'=>self::RETENTION_DAYS];
    }
}
