<?php
namespace App\Domain\Reporting\Actions;

use App\Domain\Notifications\Actions\PublishMarketplaceNotification;
use App\Domain\Reporting\Services\ReportDatasetBuilder;
use App\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;

/** Defines the GenerateReportExport class and its project responsibilities. */
class GenerateReportExport
{
    /** Initializes the GenerateReportExport instance and its dependencies. */
    public function __construct(private readonly ReportDatasetBuilder $builder,private readonly PublishMarketplaceNotification $notify){}

    /** Executes the generate report export operation. */
    public function execute(ReportExport $export):ReportExport
    {
        $claimed=DB::transaction(/** Inline callback for this operation. */ function()use($export):?ReportExport{
            $locked=ReportExport::query()->whereKey($export->id)->lockForUpdate()->first();
            if(!$locked||$locked->status!=='queued')return null;
            $locked->update(['status'=>'processing','started_at'=>now(),'error_message'=>null]);
            return $locked;
        },3);
        if(!$claimed)return $export->fresh();
        $export=$claimed;
        $tmp=tempnam(sys_get_temp_dir(),'vsn-report-');
        try{
            $dataset=$this->builder->build($export->report_type,$export->filters??[]);
            $max=max(1,(int)config('vsn.reporting.max_export_rows',100000));
            if(count($dataset['rows'])>$max)throw new \RuntimeException("Report exceeds the {$max} row export limit. Narrow the date range or filters.");
            $fh=fopen($tmp,'wb');if(!$fh)throw new \RuntimeException('Could not create export file.');
            fwrite($fh,"\xEF\xBB\xBF");fputcsv($fh,$dataset['headers']);
            foreach($dataset['rows'] as $row){$cells=[];foreach($dataset['headers'] as $h)$cells[]=$this->safeCell($row[$h]??null);fputcsv($fh,$cells);}fflush($fh);fclose($fh);
            $sha=hash_file('sha256',$tmp);$size=filesize($tmp)?:0;$disk=(string)config('vsn.reporting.export_disk','local');$path='reports/'.now()->format('Y/m').'/'.$export->public_id.'.csv';
            $read=fopen($tmp,'rb');Storage::disk($disk)->put($path,$read,['visibility'=>'private']);if(is_resource($read))fclose($read);
            $export->update(['status'=>'ready','storage_disk'=>$disk,'storage_path'=>$path,'mime_type'=>'text/csv; charset=UTF-8','sha256'=>$sha,'size_bytes'=>$size,'rows_count'=>count($dataset['rows']),'ready_at'=>now()]);
            $user=$export->requester()->first();if($user)$this->notify->execute($user,'reports','report_ready','Report ready',str_replace('_',' ',ucwords($export->report_type,'_')).' export is ready to download.',"report-ready:{$export->public_id}",'/admin/analytics','report_export',$export->public_id,['reportType'=>$export->report_type]);
        }catch(\Throwable $e){$export->update(['status'=>'failed','error_message'=>mb_substr($e->getMessage(),0,2000)]);}finally{if(is_file($tmp))@unlink($tmp);}
        return $export->fresh();
    }

    /** Handles safe cell for the generate report export workflow. */
    private function safeCell(mixed $value):string|int|float
    {
        if($value===null)return '';
        if(is_bool($value))return $value?'1':'0';
        if(is_int($value)||is_float($value))return $value;
        if(is_array($value)||is_object($value))$value=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $value=(string)$value;
        if(preg_match('/^[=+\-@]/u',$value))$value="'".$value;
        return $value;
    }
}
