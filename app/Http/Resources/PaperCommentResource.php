<?php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaperCommentResource extends JsonResource
{
    public function toArray($request): array {
        return [
            'id'         => $this->id,
            'paper_id'   => $this->paper_id,
            'parent_id'  => $this->parent_id,
            'body'       => $this->body,
            'created_at' => $this->created_at,
            'user'       => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null,
        ];
    }
}
