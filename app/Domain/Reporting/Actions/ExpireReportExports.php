<?php
namespace App\Domain\Reporting\Actions;

use App\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
/** Defines the ExpireReportExports class and its project responsibilities. */
class ExpireReportExports
{
    /** Executes the expire report exports operation. */
    public function execute(int $limit=200):int{$count=0;ReportExport::query()->where('status','ready')->whereNotNull('expires_at')->where('expires_at','<=',now())->orderBy('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function($e)use(&$count){if($e->storage_disk&&$e->storage_path)Storage::disk($e->storage_disk)->delete($e->storage_path);$e->update(['status'=>'expired']);$count++;});return $count;}
}
