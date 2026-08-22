<?php
namespace App\Domain\Reporting\Actions;

use App\Models\ReportExport;
/** Defines the ProcessQueuedReportExports class and its project responsibilities. */
class ProcessQueuedReportExports
{
    /** Initializes the ProcessQueuedReportExports instance and its dependencies. */
    public function __construct(private readonly GenerateReportExport $generate){}
    /** Executes the process queued report exports operation. */
    public function execute(?int $limit=null):int{$count=0;$limit=$limit??(int)config('vsn.reporting.generate_batch_size',10);ReportExport::query()->where('status','queued')->orderBy('id')->limit($limit)->get()->each(/** Inline callback for this operation. */ function($e)use(&$count){$this->generate->execute($e);$count++;});return $count;}
}
