<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            'description' => $this->description,
            'location' => $this->location,
            'budget_min' => $this->budget_min ? (float) $this->budget_min : null,
            'budget_max' => $this->budget_max ? (float) $this->budget_max : null,
            'status' => $this->status,
            'total_value' => $this->when(isset($this->total_value), fn () => (float) $this->total_value),
            'has_active_dispute' => $this->when(isset($this->disputes), fn () => $this->hasActiveDispute()),
            'client' => $this->whenLoaded('client', fn () => new UserResource($this->client)),
            'company' => $this->whenLoaded('company', fn () => new CompanyResource($this->company)),
            'milestones' => $this->whenLoaded('milestones'),
            'disputes' => $this->whenLoaded('disputes'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
