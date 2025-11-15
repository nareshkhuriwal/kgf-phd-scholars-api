<?php
// app/Http/Resources/SupervisorResource.php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupervisorResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'name'           => $this->name,
            'email'          => $this->email,
            'phone'          => $this->phone,
            'employeeId'     => $this->employee_id,  // camelCase for frontend
            'department'     => $this->department,
            'specialization' => $this->specialization,
            'notes'          => $this->notes,
            'role'           => $this->role,
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
