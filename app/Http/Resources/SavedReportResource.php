<?php
// app/Http/Resources/Reports/SavedReportResource.php
namespace App\Http\Resources\Reports;

use Illuminate\Http\Resources\Json\JsonResource;

class SavedReportResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'template'   => $this->template,
            'format'     => $this->format,
            'filename'   => $this->filename,
            'filters'    => $this->filters,
            'selections' => $this->selections,
            'created_by' => $this->created_by,
            'updated_at' => optional($this->updated_at)->toISOString(),
        ];
    }
}
